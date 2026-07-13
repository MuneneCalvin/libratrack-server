<?php

declare(strict_types=1);

namespace LibraTrack\Controllers;

use LibraTrack\Core\Request;
use LibraTrack\Core\Response;
use LibraTrack\Repositories\SettingsRepository;

final class SettingsController
{
    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    public function show(Request $request): Response
    {
        return Response::success($this->settings->all());
    }

    public function update(Request $request): Response
    {
        return Response::success($this->settings->update($request->json ?? []));
    }
}
