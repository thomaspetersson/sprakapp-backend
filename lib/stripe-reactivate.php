<?php
// Helper: Reactivate a cancelled Stripe subscription
function reactivateStripeSubscription($subscriptionId, $secretKey) {
    $ch = curl_init("https://api.stripe.com/v1/subscriptions/{$subscriptionId}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'cancel_at_period_end' => 'false'
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $secretKey,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($httpCode !== 200) {
        $logMsg = date('c') . ' Stripe reactivate FAIL (HTTP ' . $httpCode . '): ' . $response . "\n";
        if ($curlError) {
            $logMsg .= 'cURL error: ' . $curlError . "\n";
        }
        file_put_contents(dirname(__FILE__) . '/../api/stripe-reactivate-debug.log', $logMsg, FILE_APPEND);
        error_log('Stripe API error (reactivate, HTTP ' . $httpCode . '): ' . $response);
        throw new Exception('Failed to reactivate subscription: ' . $response);
    }
    return json_decode($response, true);
}
