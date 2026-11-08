<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Models\RfqRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.storefront')]
class CustomerRfqs extends Component
{
    use WithPagination;

    public string $tab = 'all';

    public ?int $declineRfqId = null;

    public string $declineReason = '';

    /**
     * @var array<int, string>
     */
    protected array $allowedTabs = ['all', 'quoted', 'pending', 'accepted', 'declined'];

    public function mount(): void
    {
        if (! Auth::check()) {
            $this->redirectRoute('login');
        }
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, $this->allowedTabs, true)) {
            $this->tab = $tab;
            $this->resetPage();
        }
    }

    public function confirmDecline(int $id): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        $rfq = RfqRequest::whereKey($id)
            ->where(function ($q) use ($user) {
                $q->where('buyer_id', $user->id);
                if ($user->email) {
                    $q->orWhereRaw('LOWER(buyer_email) = ?', [strtolower($user->email)]);
                }
            })
            ->first();

        if ($rfq && in_array($rfq->status, ['pending', 'quoted'], true)) {
            $this->declineRfqId = $id;
            $this->declineReason = '';
        } else {
            $this->declineRfqId = null;
        }
    }

    public function closeDeclineModal(): void
    {
        $this->declineRfqId = null;
        $this->declineReason = '';
    }

    public function submitDecline(): void
    {
        $user = Auth::user();
        if (! $user || ! $this->declineRfqId) {
            return;
        }

        $this->validate([
            'declineReason' => ['nullable', 'string', 'max:1000'],
        ]);

        $affected = RfqRequest::whereKey($this->declineRfqId)
            ->where(function ($q) use ($user) {
                $q->where('buyer_id', $user->id);
                if ($user->email) {
                    $q->orWhereRaw('LOWER(buyer_email) = ?', [strtolower($user->email)]);
                }
            })
            ->whereIn('status', ['pending', 'quoted'])
            ->update([
                'status' => 'rejected',
                'rejected_by' => 'buyer',
                'rejection_reason' => $this->declineReason ?: null,
            ]);

        $declinedId = $this->declineRfqId;
        $this->closeDeclineModal();

        if ($affected > 0) {
            session()->flash('status', "Quotation #QT-RFQ-{$declinedId} has been declined.");
        }
    }

    public function render(): View
    {
        $user = Auth::user();
        $userId = $user?->id;
        $userEmail = $user?->email ? strtolower($user->email) : null;

        $userScope = function ($query) use ($userId, $userEmail) {
            $query->where(function ($sub) use ($userId, $userEmail) {
                $sub->where('buyer_id', $userId);
                if ($userEmail) {
                    $sub->orWhereRaw('LOWER(buyer_email) = ?', [$userEmail]);
                }
            });
        };

        $counts = [
            'all' => RfqRequest::where($userScope)->count(),
            'quoted' => RfqRequest::where($userScope)
                ->where('status', 'quoted')
                ->where(function ($q) {
                    $q->whereNull('valid_until')->orWhere('valid_until', '>', now());
                })
                ->count(),
            'pending' => RfqRequest::where($userScope)
                ->where('status', 'pending')
                ->count(),
            'accepted' => RfqRequest::where($userScope)
                ->where('status', 'accepted')
                ->count(),
            'declined' => RfqRequest::where($userScope)
                ->where(function ($q) {
                    $q->where('status', 'rejected')
                        ->orWhere(function ($sq) {
                            $sq->where('status', 'quoted')
                                ->whereNotNull('valid_until')
                                ->where('valid_until', '<=', now());
                        });
                })
                ->count(),
        ];

        $rfqsQuery = RfqRequest::with(['product.unit', 'seller.sellerProfile'])
            ->where($userScope);

        if ($this->tab === 'pending') {
            $rfqsQuery->where('status', 'pending');
        } elseif ($this->tab === 'quoted') {
            $rfqsQuery->where('status', 'quoted')
                ->where(function ($q) {
                    $q->whereNull('valid_until')->orWhere('valid_until', '>', now());
                });
        } elseif ($this->tab === 'accepted') {
            $rfqsQuery->where('status', 'accepted');
        } elseif ($this->tab === 'declined') {
            $rfqsQuery->where(function ($q) {
                $q->where('status', 'rejected')
                    ->orWhere(function ($sq) {
                        $sq->where('status', 'quoted')
                            ->whereNotNull('valid_until')
                            ->where('valid_until', '<=', now());
                    });
            });
        }

        $rfqs = $rfqsQuery->latest('id')->paginate(10);

        $decliningRfq = $this->declineRfqId
            ? RfqRequest::with('product')->whereKey($this->declineRfqId)->first()
            : null;

        return view('livewire.storefront.customer-rfqs', [
            'rfqs' => $rfqs,
            'counts' => $counts,
            'user' => $user,
            'decliningRfq' => $decliningRfq,
        ]);
    }
}
