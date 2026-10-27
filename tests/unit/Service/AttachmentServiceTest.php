<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Db\AppointmentAttachmentMapper;
use OCA\Attendance\Service\AttachmentService;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class AttachmentServiceTest extends TestCase {
	/** Issue #330: a hand-built "/f/…" path lacks /index.php/ on servers without mod_rewrite. */
	public function testDownloadUrlComesFromTheFilesRoute(): void {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->expects($this->once())
			->method('linkToRouteAbsolute')
			->with('files.viewcontroller.showFile', ['fileid' => 42])
			->willReturn('https://cloud.example.com/index.php/f/42');
		$urlGenerator->expects($this->never())->method('getAbsoluteURL');

		$service = new AttachmentService(
			$this->createMock(AppointmentAttachmentMapper::class),
			$this->createMock(IRootFolder::class),
			$urlGenerator,
		);

		$this->assertSame('https://cloud.example.com/index.php/f/42', $service->getAttachmentDownloadUrl(42));
	}
}
