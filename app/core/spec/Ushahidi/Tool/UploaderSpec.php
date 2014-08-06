<?php

namespace spec\Ushahidi\Tool;

use Ushahidi\Tool\Filesystem;
use Ushahidi\Tool\FileData;
use Ushahidi\Tool\UploadData;

use PhpSpec\ObjectBehavior;
use Prophecy\Argument;

class UploaderSpec extends ObjectBehavior
{
	function let(Filesystem $fs)
	{
		$this->beConstructedWith($fs);
	}

	function it_is_initializable()
	{
		$this->shouldHaveType('Ushahidi\Tool\Uploader');
	}

	function it_does_convert_uploads_to_files(UploadData $input, $fs)
	{
		// define the filename to avoid the unique prefix being added, making the
		// filepath consistently testable
		$filename = 'test-file.png';
		$filepath = 't/e/' . $filename;

		// Create a temporary file, for a fake upload.
		$tmpfile = tempnam(sys_get_temp_dir(), 'spec');

		// The filesystem will consume a stream, but mocking it is pointless for the spec.
		$stream = Argument::any();

		// Define the upload...
		$input->name     = 'upload.png';
		$input->tmp_name = $tmpfile;
		$input->type     = 'image/png';
		$input->size     = 1024;
		$input->error    = UPLOAD_ERR_OK;

		// ... which will be written from the stream
		$fs->putStream($filepath, $stream)->shouldBeCalled();

		// ... and maintain the same size and mime type
		$fs->getSize($filepath)->willReturn(1024);
		$fs->getMimetype($filepath)->willReturn('image/png');

		// ... resulting a file.
		$this->upload($input, $filename)->shouldReturnAnInstanceOf('Ushahidi\Tool\FileData');
	}
}
