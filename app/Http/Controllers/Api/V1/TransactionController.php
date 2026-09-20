<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTransactionRequest;
use App\Models\Transaction;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->transactions()->with('category')->latest('occurred_on')->latest('id');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }
        if ($request->filled('month')) {
            $month = $request->string('month')->toString();
            $query->whereYear('occurred_on', substr($month, 0, 4))
                ->whereMonth('occurred_on', substr($month, 5, 2));
        }
        if ($request->filled('search')) {
            $search = '%'.$request->string('search').'%';
            $query->where(function ($builder) use ($search) {
                $builder->where('note', 'like', $search)
                    ->orWhereHas('category', fn ($category) => $category->where('name', 'like', $search));
            });
        }

        return response()->json([
            'data' => $query->get()->map(fn (Transaction $transaction) => $this->payload($transaction)),
        ]);
    }

    public function store(StoreTransactionRequest $request, NotificationService $notifications): JsonResponse
    {
        $data = $request->safe()->except('attachment');
        $data['user_id'] = $request->user()->id;
        $data['attachment_path'] = $this->storeAttachment($request);

        $transaction = Transaction::query()->create($data)->load('category');
        $notifications->afterTransaction($request->user(), $transaction);

        return response()->json(['data' => $this->payload($transaction)], 201);
    }

    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorize('view', $transaction);

        return response()->json(['data' => $this->payload($transaction->load('category'))]);
    }

    public function update(StoreTransactionRequest $request, Transaction $transaction, NotificationService $notifications): JsonResponse
    {
        $this->authorize('update', $transaction);
        $data = $request->safe()->except('attachment');
        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $this->storeAttachment($request);
        }
        $transaction->update($data);
        $notifications->afterTransaction($request->user(), $transaction->fresh());

        return response()->json(['data' => $this->payload($transaction->fresh('category'))]);
    }

    public function destroy(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorize('delete', $transaction);
        $transaction->delete();

        return response()->json(['message' => 'Transaction supprimée.']);
    }

    private function storeAttachment(Request $request): ?string
    {
        if (! $request->hasFile('attachment')) {
            return null;
        }

        return $request->file('attachment')->store('attachments', 'public');
    }

    private function payload(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'type' => $transaction->type,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
            'occurred_on' => $transaction->occurred_on?->toDateString(),
            'note' => $transaction->note,
            'payment_method' => $transaction->payment_method,
            'attachment_url' => $transaction->attachment_path
                ? Storage::disk('public')->url($transaction->attachment_path)
                : null,
            'category' => $transaction->category ? [
                'id' => $transaction->category->id,
                'slug' => $transaction->category->slug,
                'name' => $transaction->category->name,
                'type' => $transaction->category->type,
                'icon' => $transaction->category->icon,
                'color' => $transaction->category->color,
            ] : null,
        ];
    }
}
