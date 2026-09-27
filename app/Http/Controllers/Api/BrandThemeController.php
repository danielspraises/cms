<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrandTheme;
use App\Models\Society;
use Illuminate\Http\Request;

class BrandThemeController extends Controller
{
    private array $colorRules = [
        'primary' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        'secondary' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        'accent' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        'background' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        'text' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
    ];

    /** GET /platform/branding — the platform's own default theme. Super admin only. */
    public function platformTheme()
    {
        return BrandTheme::firstOrCreate(
            ['scope' => 'platform'],
            ['colors' => BrandTheme::DEFAULT_COLORS]
        );
    }

    public function updatePlatformTheme(Request $request)
    {
        $validated = $request->validate(['colors' => 'required|array', ...$this->prefixed('colors')]);

        $theme = BrandTheme::firstOrCreate(['scope' => 'platform'], ['colors' => BrandTheme::DEFAULT_COLORS]);
        $theme->update(['colors' => $validated['colors'], 'updated_by' => $request->user()->id]);

        return $theme;
    }

    /**
     * GET/PATCH /societies/{society}/branding
     * A society admin edits their own society's colors. A platform admin
     * can also reach this endpoint (per your requirement that the super
     * admin can edit any society's colors too) — enforced in the route
     * middleware/policy, not duplicated here.
     */
    public function show(Society $society)
    {
        return BrandTheme::firstOrCreate(
            ['scope' => 'society', 'society_id' => $society->id],
            ['colors' => $society->effectiveColors()]
        );
    }

    public function update(Request $request, Society $society)
    {
        $validated = $request->validate(['colors' => 'required|array', ...$this->prefixed('colors')]);

        $theme = BrandTheme::firstOrCreate(
            ['scope' => 'society', 'society_id' => $society->id],
            ['colors' => BrandTheme::DEFAULT_COLORS]
        );
        $theme->update(['colors' => $validated['colors'], 'updated_by' => $request->user()->id]);

        return $theme;
    }

    private function prefixed(string $prefix): array
    {
        $rules = [];
        foreach ($this->colorRules as $key => $rule) {
            $rules["{$prefix}.{$key}"] = $rule;
        }
        return $rules;
    }
}
