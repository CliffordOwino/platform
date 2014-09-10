<?php defined('SYSPATH') OR die('No direct access allowed.');

/**
 * Ushahidi Post Repository
 *
 * @author     Ushahidi Team <team@ushahidi.com>
 * @package    Ushahidi\Application
 * @copyright  2014 Ushahidi
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU Affero General Public License Version 3 (AGPL3)
 */

use Ushahidi\Entity\Post;
use Ushahidi\Entity\PostRepository;
use Ushahidi\Usecase\Post\UpdatePostRepository;
use Ushahidi\Entity\FormAttributeRepository;
use Ushahidi\Entity\TagRepository;
use Ushahidi\Entity\UserRepository;
use Ushahidi\Entity\PostSearchData;
use Aura\DI\InstanceFactory;

class Ushahidi_Repository_Post extends Ushahidi_Repository implements PostRepository, UpdatePostRepository
{
	protected $form_attribute_repo;
	protected $post_value_factory;
	protected $bounding_box_factory;
	protected $tag_repo;

	/**
	 * Construct
	 * @param Database                              $db
	 * @param FormAttributeRepository               $form_attribute_repo
	 * @param Ushahidi_Repository_PostValueFactory  $post_value_factory
	 * @param Aura\DI\InstanceFactory               $bounding_box_factory
	 */
	public function __construct(
			Database $db,
			FormAttributeRepository $form_attribute_repo,
			Ushahidi_Repository_PostValueFactory $post_value_factory,
			InstanceFactory $bounding_box_factory,
			TagRepository $tag_repo
		)
	{
		parent::__construct($db);

		$this->form_attribute_repo = $form_attribute_repo;
		$this->post_value_factory = $post_value_factory;
		$this->bounding_box_factory = $bounding_box_factory;
		$this->tag_repo = $tag_repo;
	}

	// Ushahidi_Repository
	protected function getTable()
	{
		return 'posts';
	}

	// Ushahidi_Repository
	protected function getEntity(Array $data = null)
	{
		$post = new Post($data);

		// Get custom form attribute values
		$values = $this->post_value_factory->proxy()->getAllForPost($data['id']);
		$post->setData(['values' => $values]);

		// Get tags
		$tags = $this->getTagsForPost($data['id']);
		$post->setData(['tags' => $tags]);

		return $post;
	}

	// PostRepository
	public function get($id, $parent_id = NULL)
	{
		return $this->getEntity($this->selectOne(compact('id', 'parent_id')));
	}

	// PostRepository
	public function getByLocale($locale, $parent_id)
	{
		return $this->getEntity($this->selectOne(compact('locale', 'parent_id')));
	}

	// PostRepository
	public function search(PostSearchData $search, Array $params = null)
	{
		$where = Arr::extract($search->asArray(), ['status', 'locale', 'slug']);
		if ($search->user)
		{
			$where['user_id'] = $search->user;
		}
		if ($search->form)
		{
			$where['form_id'] = $search->form;
		}

		// Start the query, removing empty values
		$query = $this
			->selectQuery(array_filter($where))
			->distinct(TRUE);

		if ($search->q)
		{
			// title and content text searching
			$query
			->and_where_open()
			->where('title', 'LIKE', "%$search->q%")
			->or_where('content', 'LIKE', "%$search->q%")
			->and_where_close();
		}

		// date chcks
		if ($search->created_after)
		{
			$created_after = strtotime($search->created_after);
			$query->where('created', '>=', $created_after);
		}

		if ($search->created_before)
		{
			$created_before = strtotime($search->created_before);
			$query->where('created', '<=', $created_before);
		}

		if ($search->updated_after)
		{
			$updated_after = strtotime($search->updated_after);
			$query->where('updated', '>=', $updated_after);
		}

		if ($search->updated_before)
		{
			$updated_before = strtotime($search->updated_before);
			$query->where('updated', '<=', $updated_before);
		}

		// Bounding box search
		// Create geometry from bbox
		if ($search->bbox)
		{
			$sub = $this->getBoundingBoxSubquery($search->bbox);
			$query
				->join([$sub, 'Filter_BBox'], 'INNER')
				->on('posts.id', '=', 'Filter_BBox.post_id');
		}

		// Filter by tag
		if ($search->tags)
		{
			if (isset($search->tags['any']))
			{
				$query
					->join('posts_tags')->on('posts.id', '=', 'posts_tags.post_id')
					->where('tag_id', 'IN', $search->tags['any']);
			}

			if (isset($search->tags['all']))
			{
				foreach ($search->tags['all'] as $tag)
				{
					$sub = DB::select('post_id')
						->from('posts_tags')
						->where('tag_id', '=', $tag);

					$query
						->where('posts.id', 'IN', $sub);
				}
			}
		}

		// Filter by set
		if ($search->set)
		{
			$query
				->join('posts_sets', 'INNER')->on('posts.id', '=', 'posts_sets.post_id')
				->where('posts_sets.set_id', '=', $search->set);
		}

		// Attributes
		if ($search->values)
		{
			foreach ($search->values as $key => $value)
			{
				$attribute = $this->form_attribute_repo->getByKey($key);

				$sub = $this->post_value_factory
					->getRepo($attribute->type)
					->getValueQuery($attribute->id, $value);

				$query
					->join([$sub, 'Filter_'.ucfirst($key)], 'INNER')
					->on('posts.id', '=', 'Filter_'.ucfirst($key).'.post_id');
			}
		}

		if (!empty($params['type']))
		{
			$query->where('posts.type', '=', $params['type']);
		}

		if (!empty($params['parent_id']))
		{
			$query->where('parent_id', '=', $params['parent_id']);
		}

		if (!empty($params['orderby'])) {
			$query->order_by($this->getTable().'.'.$params['orderby'], Arr::get($params, 'order'));
		}

		if (!empty($params['offset'])) {
			$query->offset($params['offset']);
		}
		if (!empty($params['limit'])) {
			$query->limit($params['limit']);
		}

		$results = $query->execute($this->db);

		return $this->getCollection($results->as_array());
	}

	/**
	 * Get a subquery to return post_point entries within a bounding box
	 * @param  string $bbox Bounding box
	 * @return Database_Query
	 */
	private function getBoundingBoxSubquery($bbox)
	{
		list($bb_west, $bb_north, $bb_east, $bb_south) = array_map('floatval', explode(',', $bbox));

		$bounding_box_factory = $this->bounding_box_factory;
		$boundingbox = $bounding_box_factory($bb_west, $bb_north, $bb_east, $bb_south);

		return DB::select('post_id')
			->from('post_point')
			->where(
				DB::expr(
					'CONTAINS(GeomFromText(:bounds), value)',
					[':bounds' => $boundingbox->toWKT()]
				),
				'=',
				1
			);
	}

	/**
	 * Get tags for a post
	 * @param  int   $id  post id
	 * @return array      tag ids for post
	 */
	private function getTagsForPost($id)
	{
		$result = DB::select('tag_id')->from('posts_tags')
			->where('post_id', '=', $id)
			->execute($this->db);
		return $result->as_array(NULL, 'tag_id');
	}


	// UpdatePostRepository
	public function isSlugAvailable($slug)
	{
		return $this->selectCount(compact('slug')) === 0;
	}


	// UpdatePostRepository
	public function doesFormExist($form_id)
	{
		$result = DB::select('id')->from('forms')
			->where('id', '=', $form_id)
			->limit(1)
			->execute($this->db);

		$form = $result->current();

		return (boolean)$form;
	}

	// UpdatePostRepository
	public function doesLocaleAlreadyExist($locale, $parent_id, $type)
	{
		// If this isn't a translation of an existing post, skip
		if ($type != 'translation')
		{
			return TRUE;
		}

		// Is locale the same as parent?
		$parent = $this->get($parent_id);
		if ($parent->locale === $locale)
		{
			return FALSE;
		}

		// Check for other translations
		return $this->selectCount([
			'type' => 'translation',
			'parent_id' => $parent_id,
			'locale' => $locale
			]) === 0;
	}

	// UpdatePostRepository
	public function updatePost($id, Array $update)
	{
		if ($id && $update)
		{
			// Update the post entry if it changed
			$post_update = $update;
			unset($post_update['values'], $post_update['tags']);
			if (! empty($post_update))
			{
				$this->update(compact('id'), $post_update);
			}

			// Update post-tags
			$this->updatePostTags($id, $update['tags']);

			// Update post-values
			$this->updatePostValues($id, $update['values']);

			// @todo Save revision
			//$this->createRevision($id);
		}
		return $this->get($id);
	}

	protected function updatePostValues($post_id, $values)
	{
		$saved_value_ids = [];
		foreach ($values as $key => $value)
		{
			$attribute = $this->form_attribute_repo->getByKey($key);
			$repo = $this->post_value_factory
					->getRepo($attribute->type);

			foreach($value as $v)
			{
				if (! empty($v['id']))
				{
					$id = $v['id'];
					$repo->updateValue($v['id'], $v['value'], $attribute->id, $post_id);
				}
				else
				{
					$id = $repo->createValue($v['value'], $attribute->id, $post_id);
				}

				$saved_value_ids[$attribute->type][] = $id;
			}
		}

		// Delete any old values that weren't passed through
		foreach($this->post_value_factory->getTypes() as $type)
		{
			$repo = $this->post_value_factory
				->getRepo($type);

			$ids = ! empty($saved_value_ids[$type]) ? $saved_value_ids[$type] : [0];

			$repo->deleteNotIn($post_id, $ids);
		}
	}

	protected function updatePostTags($post_id, $tags)
	{
		// Load existing tags
		$existing = $this->getTagsForPost($post_id);

		$insert = DB::insert('posts_tags', ['post_id', 'tag_id']);

		$tag_ids = [0];
		$new_tags = FALSE;
		foreach ($tags as $tag)
		{
			// Find the tag by id or name
			// @todo this should happen before we even get here
			$tag_entity = $this->tag_repo->getByTag($tag);
			if (! $tag_entity->id)
			{
				$tag_entity = $this->tag_repo->get($tag);
			}

			// Does the post already havet this tag?
			if (! in_array($tag_entity->id, $existing))
			{
				// Add to insert query
				$insert->values([$post_id, $tag_entity->id]);
				$new_tags = TRUE;
			}

			$tag_ids[] = $tag_entity->id;
		}

		// Save
		if ($new_tags)
		{
			$insert->execute($this->db);
		}

		// Remove any other tags
		DB::delete('posts_tags')
			->where('tag_id', 'NOT IN', $tag_ids)
			->execute($this->db);
	}

}
