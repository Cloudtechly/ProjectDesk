<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateLocaleRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;

class LocalePreferenceController extends Controller
{
    public function __invoke(UpdateLocaleRequest $request): RedirectResponse
    {
        $locale = $request->validated('locale');
        abort_unless(is_string($locale), 422);

        App::setLocale($locale);

        return back()->withCookie(Cookie::make(
            (string) config('project-desk.localization.cookie.name', 'project_desk_locale'),
            $locale,
            (int) config('project-desk.localization.cookie.minutes', 60 * 24 * 365),
        ));
    }
}
