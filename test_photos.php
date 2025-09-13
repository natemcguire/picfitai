<?php
// test_photos.php - Debug photo management
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/UserPhotoService.php';

Session::start();

if (!Session::isLoggedIn()) {
    echo "Not logged in";
    exit;
}

$user = Session::getCurrentUser();
$userPhotos = UserPhotoService::getUserPhotos($user['id']);

echo "<h1>Debug User Photos</h1>";
echo "<h2>User ID: " . $user['id'] . "</h2>";
echo "<h2>Photo Count: " . count($userPhotos) . "</h2>";

echo "<h3>Raw Photos Array:</h3>";
echo "<pre>" . print_r($userPhotos, true) . "</pre>";

echo "<h3>With URLs added:</h3>";
foreach ($userPhotos as &$photo) {
    $photo['url'] = UserPhotoService::getPhotoUrl($photo['filename']);
}
echo "<pre>" . print_r($userPhotos, true) . "</pre>";

echo "<h3>JSON output for JavaScript:</h3>";
echo "<pre>" . json_encode($userPhotos) . "</pre>";