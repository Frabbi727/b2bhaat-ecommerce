<?php

namespace App\Filament\Admin\Resources\Banners\Schemas;

use App\Models\Banner;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

/**
 * Banner editor. Fields follow what the shop actually renders for each placement (product-catalog.blade.php and
 * the storefront layout): the announcement bar is text only, offer cards have no button, and only the home slider
 * uses a separate phone picture.
 */
class BannerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Group::make([
                    self::placementSection(),
                    self::wordsSection(),
                    self::pictureSection(),
                ])->columnSpan(['lg' => 2]),

                Group::make([
                    self::visibilitySection(),
                ])->columnSpan(['lg' => 1]),
            ]);
    }

    private static function placementSection(): Section
    {
        return Section::make('Where it appears')
            ->schema([
                Radio::make('position')
                    ->hiddenLabel()
                    ->options(Banner::POSITIONS)
                    ->descriptions([
                        Banner::POSITION_HERO => 'Large picture at the top of the home page. Several banners take turns.',
                        Banner::POSITION_PROMO => 'Smaller picture cards below the slider, two to a row.',
                        Banner::POSITION_TOP_STRIP => 'One line of text above the menu on every page. No picture.',
                    ])
                    ->default(Banner::POSITION_HERO)
                    ->live()
                    ->required(),
            ]);
    }

    private static function wordsSection(): Section
    {
        return Section::make('Words and link')
            ->schema([
                TextInput::make('title')
                    ->label('Headline')
                    ->placeholder('e.g. Mini LED TV week')
                    ->helperText('Keep it short. About 6 words reads well on a phone.')
                    ->maxLength(255)
                    ->required(),

                TextInput::make('subtitle')
                    ->label('Supporting line')
                    ->placeholder('e.g. Up to 40% off, free delivery in Dhaka')
                    ->helperText(fn (Get $get): ?string => $get('position') === Banner::POSITION_TOP_STRIP ? 'Shown after the headline on computers only.' : null)
                    ->maxLength(255),

                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    TextInput::make('badge_text')
                        ->label('Small label')
                        ->placeholder('e.g. Flash deal')
                        ->helperText('Optional tag above the headline.')
                        ->maxLength(100),

                    TextInput::make('button_text')
                        ->label('Button text')
                        ->placeholder('Shop Now')
                        ->default('Shop Now')
                        ->maxLength(50)
                        ->visible(fn (Get $get): bool => $get('position') === Banner::POSITION_HERO),
                ])->visible(fn (Get $get): bool => $get('position') !== Banner::POSITION_TOP_STRIP),

                TextInput::make('link_url')
                    ->label('Link')
                    ->placeholder('/catalog?channel=wholesale')
                    ->helperText('Where shoppers go when they tap it. Use a page on this site, starting with /, or a full https:// address.')
                    ->maxLength(255)
                    ->regex('#^(/|https?://)#')
                    ->validationMessages(['regex' => 'Start the link with / (a page on this site) or https://.'])
                    ->required(),
            ]);
    }

    private static function pictureSection(): Section
    {
        return Section::make('Picture')
            ->description('JPG, PNG or WebP, up to 4 MB. Keep words out of the picture edges; they can be cropped.')
            ->visible(fn (Get $get): bool => $get('position') !== Banner::POSITION_TOP_STRIP)
            ->schema([
                Grid::make(['default' => 1, 'md' => 3])->schema([
                    self::imageUpload('desktop_image_url')
                        ->label(fn (Get $get): string => $get('position') === Banner::POSITION_HERO ? 'Computer picture' : 'Picture')
                        ->helperText(fn (Get $get): string => $get('position') === Banner::POSITION_HERO
                            ? 'Wide, 21:9. For example 1920 × 820 px.'
                            : 'Wide and short. For example 1200 × 360 px.')
                        ->columnSpan(['md' => 2])
                        ->required(),

                    self::imageUpload('mobile_image_url')
                        ->label('Phone picture')
                        ->helperText('Optional, 4:3. For example 1200 × 900 px. Without it, phones show the computer picture cropped.')
                        ->visible(fn (Get $get): bool => $get('position') === Banner::POSITION_HERO),
                ]),
            ]);
    }

    private static function visibilitySection(): Section
    {
        return Section::make('When it shows')
            ->schema([
                TextEntry::make('live_status')
                    ->label('Right now')
                    ->badge()
                    ->state(fn (?Banner $record): ?string => $record?->liveStatusLabel())
                    ->color(fn (?Banner $record): string => $record?->isAvailableNow() ? 'success' : 'gray')
                    ->hiddenOn('create'),

                Toggle::make('is_active')
                    ->label('Show in the shop')
                    ->helperText('Turn off to take it down without deleting it.')
                    ->default(true),

                DateTimePicker::make('start_at')
                    ->label('Start showing')
                    ->native(false)
                    ->seconds(false)
                    ->displayFormat('d M Y, g:i A')
                    ->placeholder('Right away')
                    ->live(onBlur: true),

                DateTimePicker::make('end_at')
                    ->label('Stop showing')
                    ->native(false)
                    ->seconds(false)
                    ->displayFormat('d M Y, g:i A')
                    ->placeholder('Keep showing')
                    ->minDate(fn (Get $get): ?string => $get('start_at'))
                    ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        if (filled($value) && filled($get('start_at')) && Carbon::parse($value)->lte(Carbon::parse($get('start_at')))) {
                            $fail('Pick a stop time after the start time.');
                        }
                    }),

                TextInput::make('sort_order')
                    ->label('Order')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    ->helperText(fn (Get $get): string => $get('position') === Banner::POSITION_TOP_STRIP
                        ? 'The bar shows one banner: the active one with the lowest number.'
                        : 'Lower numbers show first.')
                    ->required(),
            ]);
    }

    /**
     * Upload to the public disk. Older banners hold full image addresses instead of uploaded files; keep and
     * preview those rather than dropping them, the same way product photos do.
     */
    private static function imageUpload(string $name): FileUpload
    {
        return FileUpload::make($name)
            ->image()
            ->disk('public')
            ->directory('banners')
            ->visibility('public')
            ->maxSize(4096)
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->imagePreviewHeight('180')
            ->fetchFileInformation(false)
            ->getUploadedFileUsing(fn (FileUpload $component, string $file, string|array|null $storedFileNames): ?array => filter_var($file, FILTER_VALIDATE_URL)
                ? ['name' => basename((string) parse_url($file, PHP_URL_PATH)) ?: 'banner', 'size' => 0, 'type' => null, 'url' => $file]
                : $component->getUploadedFile($file, $storedFileNames));
    }
}
