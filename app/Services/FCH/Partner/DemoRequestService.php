<?php

namespace App\Services\FCH\Partner;

use App\Services\FCH\Common\Notification\Notifier;
use App\Models\User\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Models\DemoRequest\DemoRequest;
use App\Services\Global\Notification\Mail\DemoRequestCreatedMail;
use Exception;

class DemoRequestService
{
    public function getAllDemoRequests(Request $request)
    {
        $query = DemoRequest::query();

        // 🔍 Search filter
        if ($search = $request->get('search')) {
            $search = trim($search);
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'LIKE', "%{$search}%")
                    ->orWhere('company_name', 'LIKE', "%{$search}%")
                    ->orWhere('phone_number', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('assign_to', 'LIKE', "%{$search}%")
                    ->orWhere('status', 'LIKE', "%{$search}%");
            });
        }

        // 🎯 Filter by status (if not 'All')
        if ($request->filled('status') && $request->status !== 'All') {
            $query->where('status', $request->status);
        }

        // 👤 Filter by assigned user (if not 'All')
        if ($request->filled('assign_to') && $request->assign_to !== 'All') {
            $query->where('assign_to', $request->assign_to);
        }

        // 📅 Order newest first
        $query->orderByDesc('id');

        // 🔢 Paginate
        $perPage = $request->get('per_page', 2);

        return $query->paginate($perPage);
    }

    public function store(array $data)
    {
        // ✅ Auto set creator
        $data['created_by'] = Auth::id();
        $data['business_id'] = auth()->user()->business_id;

        // Optional: if assigned_to is given, auto-fill assigned_email / phone
        if (isset($data['assigned_to'])) {
            $user = User::find($data['assigned_to']);
            if ($user) {
                $data['assigned_email'] = $user->email ?? null;
            }
        }

        $demoRequest = DemoRequest::create($data);

        // Send mail (to customer or assigned employee)
        try {
            $recipient = $demoRequest->email ?? $demoRequest->email;
            if ($recipient) {

                try {
                    app(Notifier::class)->send('demo_request.created', mail: (new DemoRequestCreatedMail($demoRequest))->to($recipient));
                } catch (Exception $e) {
                    Log::error('DemoRequest email send failed', [
                        'id' => $demoRequest->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('DemoRequest email send failed', [
                'id' => $demoRequest->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $demoRequest;
    }

    // public function update(DemoRequest $demoRequest, array $data)
    // {
    //     $demoRequest->update($data);
    //     return $demoRequest;
    // }
}
