<?php

declare(strict_types=1);

namespace OCA\Attendance\Controller;

use OCA\Attendance\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * @psalm-suppress UnusedClass
 */
class PageController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct($appName, $request);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse {
		return new TemplateResponse(
			Application::APP_ID,
			'index',
		);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function unanswered(): TemplateResponse {
		return new TemplateResponse(
			Application::APP_ID,
			'index',
		);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function past(): TemplateResponse {
		return new TemplateResponse(
			Application::APP_ID,
			'index',
		);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function all(): TemplateResponse {
		return new TemplateResponse(
			Application::APP_ID,
			'index',
		);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function appointment(int $id): TemplateResponse {
		return new TemplateResponse(
			Application::APP_ID,
			'index',
		);
	}

	/**
	 * Up to 1.42 the frontend built the detail URL by appending to the current
	 * path without stripping the "all" segment first, so every link copied or
	 * shared from the All view looks like /all/appointment/42. Those links are
	 * out in the wild; send them to the canonical URL instead of a 404.
	 *
	 * Safe to drop once those links can be assumed gone.
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function legacyAllAppointment(int $id): RedirectResponse {
		return new RedirectResponse(
			$this->urlGenerator->linkToRoute(Application::APP_ID . '.page.appointment', ['id' => $id]),
		);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function checkin(int $id): TemplateResponse {
		return new TemplateResponse(
			Application::APP_ID,
			'index',
		);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function create(): TemplateResponse {
		return new TemplateResponse(
			Application::APP_ID,
			'index',
		);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function edit(int $id): TemplateResponse {
		return new TemplateResponse(
			Application::APP_ID,
			'index',
		);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function copy(int $id): TemplateResponse {
		return new TemplateResponse(
			Application::APP_ID,
			'index',
		);
	}
}
