<?php

namespace App\Http\Controllers\MyParent;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Stripe\Stripe;
use Stripe\Checkout\Session;
use App\Models\Payment;
use App\Helpers\Pay;
use App\Helpers\Qs;

class StripeController extends Controller
{
    /**
     * Create Stripe Checkout Session and redirect to Stripe-hosted page
     */
    public function checkoutPage(Payment $payment)
    {
        // Check minimum amount for Stripe (MYR min = 0.50)
        if ($payment->amount < 0.50) {
            return back()->with('flash_danger', 'Amount must be at least RM0.50 to pay.');
        }

        Stripe::setApiKey(config('services.stripe.secret'));

        // Generate Stripe Checkout Session
        $session = Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'myr',
                    'product_data' => [
                        'name' => $payment->title,
                    ],
                    'unit_amount' => intval($payment->amount * 100),
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => route('parent.payments.stripe.success') . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('parent.payments.cancel'),
            'metadata' => [
                'payment_id' => $payment->id,
                'parent_id' => auth()->id(),
            ],
        ]);

        // Redirect user to Stripe-hosted checkout page
        return redirect($session->url);
    }

    /**
     * Handle success callback from Stripe
     */
    public function success(Request $request)
    {
        // Get the session_id from Stripe redirect
        $sessionId = $request->query('session_id');
        
        if ($sessionId) {
            try {
                Stripe::setApiKey(config('services.stripe.secret'));
                
                // Retrieve the session to get payment details
                $session = Session::retrieve($sessionId);
                
                // Get payment_id from metadata
                $paymentId = $session->metadata->payment_id ?? null;
                
                if ($paymentId && $session->payment_status === 'paid') {
                    $payment = Payment::find($paymentId);
                    
                    if ($payment && $payment->status !== 'paid') {
                        // Update payment status
                        $payment->update([
                            'status' => 'paid',
                            'stripe_payment_id' => $session->payment_intent
                        ]);
                        
                        // Mark all payment details as paid
                        foreach ($payment->paymentDetails as $detail) {
                            $detail->update([
                                'amt_paid' => $detail->amt_paid,
                                'balance' => 0
                            ]);
                        }
                    }
                }
            } catch (\Exception $e) {
                // Log error but still show success page
                \Log::error('Stripe session retrieval failed: ' . $e->getMessage());
            }
        }

        return view('pages.parent.payments.success');
    }

    /**
     * Handle cancel callback from Stripe
     */
    public function cancel()
    {
        return view('pages.parent.payments.cancel');
    }

    /**
     * Create Payment for multiple children & redirect to Stripe
     */
    public function createPayment(Request $request)
    {
        $totalAmount = collect($request->children)->sum('amount');

        if ($totalAmount < 0.50) {
            return back()->with('flash_danger', 'Total amount must be at least RM0.50.');
        }

        // 1. Create parent-level payment
        $payment = Payment::create([
            'title' => 'Online Payment (Stripe)',
            'amount' => $totalAmount,
            'method' => 'stripe',
            'my_parent_id' => auth()->id(),
            'year' => Qs::getCurrentSession(),
            'ref_no' => Pay::genRefCode(),
            'status' => 'pending',
            'description' => 'Stripe payment for multiple children'
        ]);

        // 2. Save payment details for each child
        foreach ($request->children as $child) {
            $payment->paymentDetails()->create([
                'student_record_id' => $child['student_id'],
                'amt_paid' => $child['amount']
            ]);
        }

        // 3. Create Stripe Checkout Session
        Stripe::setApiKey(config('services.stripe.secret'));

        $session = Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'myr',
                    'product_data' => [
                        'name' => $payment->title,
                    ],
                    'unit_amount' => intval($payment->amount * 100),
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => route('parent.payments.stripe.success') . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('parent.payments.cancel'),
            'metadata' => [
                'payment_id' => $payment->id,
                'parent_id' => auth()->id(),
            ],
        ]);

        // 4. Redirect user directly to Stripe-hosted checkout page
        return redirect($session->url);
    }

    /**
     * Show checkout form for entering amounts per chil
     */
    public function showCheckoutForm(Payment $payment)
    {
        // Verify the payment belongs to the authenticated parent
        if ($payment->my_parent_id !== auth()->id()) {
            abort(403, 'Unauthorized access to this payment.');
        }
        
        // Eager load the relationships to avoid N+1 queries
        $payment->load('paymentDetails.studentRecord.user');
        
        // Check if payment has details
        if ($payment->paymentDetails->isEmpty()) {
            return back()->with('flash_danger', 'This payment has no associated children. Please contact support.');
        }
        
        // Get children from payment details
        $children = $payment->paymentDetails->map(function($detail) {
            return (object)[
                'id' => $detail->student_record_id,
                'name' => $detail->studentRecord->user->name ?? 'N/A',
                'amount' => $detail->amt_paid ?? 0
            ];
        });

        return view('pages.parent.payments.checkout', compact('payment', 'children'));
    }

    /**
     * Process checkout form and redirect to Stripe
     */
    public function processCheckout(Request $request, Payment $payment)
    {
        $totalAmount = collect($request->children)->sum('amount');

        if ($totalAmount < 0.50) {
            return back()->with('flash_danger', 'Total amount must be at least RM0.50.');
        }

        // Update payment amount
        $payment->update(['amount' => $totalAmount]);

        // Update payment details for each child
        foreach ($request->children as $child) {
            $payment->paymentDetails()
                ->where('student_record_id', $child['student_id'])
                ->update(['amt_paid' => $child['amount']]);
        }

        // Create Stripe Checkout Session
        Stripe::setApiKey(config('services.stripe.secret'));

        $session = Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'myr',
                    'product_data' => [
                        'name' => $payment->title,
                    ],
                    'unit_amount' => intval($totalAmount * 100),
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => route('parent.payments.stripe.success') . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('parent.payments.cancel'),
            'metadata' => [
                'payment_id' => $payment->id,
                'parent_id' => auth()->id(),
            ],
        ]);

        return redirect($session->url);
    }
}
