<?php
$jwt = 'JWT eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJrZXkiOiJjZTFmNzE0ZS1hZTQyLTRmNTQtOTJmMi0yMzBkMTY2MmVjOTMiLCJzZWNyZXQiOiJlMzYyZWI0YjBjOGU4NTE5OTM4YjNlNzZjNTBhNWYwYmIzNjZkOWMzNWQzZTcxZTJkN2QxMGMyOWIwOWM3ZGNjIiwiaWF0IjoxNzczMDcyMzU2LCJleHAiOjI1NjE0NzIzNTZ9.y712vJs4gXyKAnuFPWAuEpCrRVeFVTErKobMtuo0XW4';

$payload = json_encode([
    "destination" => "447749715521",
    "sender"      => "Test",
    "content"     => "API test"
]);

$ch = curl_init("https://api.thesmsworks.co.uk/v1/message/send");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: $jwt",
        "Content-Type: application/json"
    ],
    CURLOPT_POSTFIELDS => $payload
]);

$response = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "HTTP $code\n\n";
echo $response;