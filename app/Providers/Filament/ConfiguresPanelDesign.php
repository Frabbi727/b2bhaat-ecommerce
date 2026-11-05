<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;

/**
 * One design system for the Admin and Seller panels: same type, neutrals, semantic colours, navigation behaviour and
 * form safety. Each panel keeps only its own primary colour so people can tell at a glance which console they are in.
 */
trait ConfiguresPanelDesign
{
    /**
     * @param  array<int|string, string>  $primary
     */
    protected function applyPanelDesign(Panel $panel, array $primary): Panel
    {
        return $panel
            ->font('Instrument Sans')
            ->colors([
                'primary' => $primary,
                'gray' => Color::Zinc,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
                'info' => Color::Sky,
            ])
            ->maxContentWidth(Width::ScreenTwoExtraLarge)
            ->sidebarCollapsibleOnDesktop()
            ->unsavedChangesAlerts()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k']);
    }
}
