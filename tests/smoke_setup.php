<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/test_bootstrap.php';
UserRepository::setPassword(1, 'SmokeAdmin123!', false);
$resetUser = UserRepository::create('smoke_reset', 'Usuario reset ficticio', 'reset@example.invalid', 'Usuario', 'SmokeReset123!', false);
BranchRepository::saveUserBranches($resetUser, [1]);
$reset = UserRepository::createPasswordReset('smoke_reset', '127.0.0.1', 'Smoke test sin correo');
Settings::saveWhatsapp(['graph_version'=>'v25.0','waba_id'=>'smoke-waba','phone_number_id'=>'smoke-phone',
    'access_token'=>'fixture-not-a-real-token','app_secret'=>'smoke-secret','verify_token'=>'smoke-verify','is_enabled'=>false], 1);
$unsubscribe = UnsubscribeRepository::tokenFor(['email'=>'unsubscribe@example.invalid', 'branch_id'=>1]);
file_put_contents(STORAGE_PATH . '/smoke-fixture.json', json_encode(['reset_token'=>$reset['token'], 'unsubscribe_token'=>$unsubscribe], JSON_THROW_ON_ERROR));
echo "SMOKE SETUP OK: credenciales ficticias y token de recuperacion local; sin correo.\n";
