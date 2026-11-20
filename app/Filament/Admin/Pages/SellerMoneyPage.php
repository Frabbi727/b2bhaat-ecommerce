<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Resources\AccountingJournalEntries\AccountingJournalEntryResource;
use App\Filament\Admin\Resources\SellerMonetization\SellerMonetizationResource;
use App\Models\User;
use App\Services\Accounting\SellerMoneyService;
use Filament\Pages\Page;

/**
 * F5 (2026-11-20 finance audit): one seller's money on one admin page, opened from the seller list or the Money
 * page. What we owe them and what they owe us are never mixed.
 */
class SellerMoneyPage extends Page
{
    protected static ?string $slug = 'sellers/{seller}/money';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.admin.pages.seller-money-page';

    public int $sellerId;

    public int $limit = 50;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user && in_array($user->role?->slug, ['super_admin', 'admin'], true));
    }

    public function mount(int|string $seller): void
    {
        $user = User::query()->whereKey($seller)->whereHas('sellerProfile')->first();
        abort_if($user === null, 404);

        $this->sellerId = (int) $user->getKey();
    }

    public function getTitle(): string
    {
        return 'Money: '.$this->sellerName();
    }

    public function sellerName(): string
    {
        $user = User::query()->with('sellerProfile:id,user_id,store_name')->find($this->sellerId, ['id', 'name']);

        return $user?->sellerProfile?->store_name ?: (string) $user?->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        return app(SellerMoneyService::class)->summary($this->sellerId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTimeline(): array
    {
        return app(SellerMoneyService::class)->timeline($this->sellerId, $this->limit + 1);
    }

    public function showMore(): void
    {
        $this->limit += 50;
    }

    public function entryUrl(int $entryId): string
    {
        return AccountingJournalEntryResource::getUrl('view', ['record' => $entryId]);
    }

    public function allEntriesUrl(): string
    {
        return AccountingJournalEntryResource::getUrl('index', ['filters' => ['seller' => ['value' => $this->sellerId]]]);
    }

    public function billsUrl(): string
    {
        return SellerMonetizationResource::getUrl('index');
    }
}
