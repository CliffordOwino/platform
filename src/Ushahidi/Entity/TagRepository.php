<?php

/**
 * Repository for Tags
 *
 * @author     Ushahidi Team <team@ushahidi.com>
 * @package    Ushahidi\Platform
 * @copyright  2014 Ushahidi
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU Affero General Public License Version 3 (AGPL3)
 */

namespace Ushahidi\Entity;

interface TagRepository
{
	/**
	 * @param  int $id
	 * @return Ushahidi\Entity\Tag
	 */
	public function get($id);

	/**
	 * @param  string $tag
	 * @return Ushahidi\Entity\Tag
	 */
	public function getByTag($tag);

	/**
	 * @param  Ushahidi\Entity\TagSearchData $data
	 * @return [Ushahidi\Entity\Tag, ...]
	 */
	public function search(TagSearchData $data);

	/**
	 * @param  string $tag
	 * @return Boolean
	 */
	public function doesTagExist($tag_or_id);
}
