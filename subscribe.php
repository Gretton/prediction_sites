<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
require_once 'auth.php';

$qrFilePath = __DIR__ . '/includes/selcom_qr.php';
if (file_exists($qrFilePath)) {
    require_once $qrFilePath;
}

requireLogin();
$user = getCurrentUser();
$premium = getPremiumStatus();

$tier = isset($_GET['tier']) ? strtolower(trim($_GET['tier'])) : 'parlay';
if (!in_array($tier, ['parlay', 'rollover', 'both'])) {
    $tier = 'parlay';
}

$duration = isset($_GET['duration']) ? strtolower(trim($_GET['duration'])) : 'monthly';
if (!in_array($duration, ['daily', 'biweekly', 'monthly'])) {
    $duration = 'monthly';
}

$price = getPlanPrice($tier, $duration);
$days = getPlanDays($tier, $duration);
$perDay = getPerDayCost($tier, $duration);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $refNumber = trim($_POST['reference_number'] ?? '');
    $phoneNumber = trim($_POST['phone_number'] ?? $user['phone']);
    $paymentMethod = $_POST['payment_method'] ?? 'selcom_lipa';
    $submittedTier = $_POST['tier'] ?? $tier;
    $submittedDuration = $_POST['duration'] ?? $duration;

    if (empty($refNumber)) {
        $error = 'Please enter payment reference number';
    } else {
        $result = submitPayment($user['id'], $refNumber, $phoneNumber, $price, $submittedTier, $submittedDuration);
        if ($result['success']) {
            $_SESSION['flash_success'] = 'Payment submitted! Ref: ' . htmlspecialchars($refNumber) . '. ' . ucfirst($submittedTier) . ' (' . $submittedDuration . ') will be activated after admin approval.';
            header("Location: dashboard");
            exit;
        } else {
            $error = $result['message'];
        }
    }
}

$durationOpts = getDurationOptions();
$plans = SUBSCRIPTION_PLANS;
$tierLabel = ['parlay' => '🎯 Parlay', 'rollover' => '🛡️ Rollover', 'both' => '💎 Both Premium'];
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<title>Subscribe - Predixa</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root { --primary: #8B5CF6; --primary-dark: #7C3AED; --primary-light: #A78BFA; --accent: #06B6D4; --accent-dark: #0891B2; --secondary: #161b22; --text-light: #e0e0e0; --text-muted: #8b949e; --border-color: #2a2e35; }
body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #0f1115 0%, #1a1f2e 100%); min-height: 100vh; padding: 40px 20px; }
.premium-card { background: var(--secondary); border: 1px solid var(--border-color); border-radius: 16px; padding: 40px; max-width: 640px; margin: 0 auto; box-shadow: 0 10px 40px rgba(139,92,246,0.1); }
.payment-method { background: rgba(139,92,246,0.05); border: 2px solid var(--border-color); border-radius: 12px; padding: 20px; margin-bottom: 20px; transition: all 0.3s; }
.payment-method:hover { border-color: var(--primary); background: rgba(139,92,246,0.1); }
.payment-method h5 { color: var(--primary); margin-bottom: 10px; font-weight: 700; }
.payment-details { background: rgba(6,182,212,0.05); border: 1px solid var(--accent); border-radius: 8px; padding: 15px; margin-top: 15px; }
.payment-details code { background: rgba(6,182,212,0.2); color: var(--accent); padding: 4px 8px; border-radius: 4px; font-size: 1.1rem; font-weight: 700; }
.qr-code { background: white; padding: 15px; border-radius: 8px; display: inline-block; margin: 15px 0; border: 2px solid var(--border-color); }
.btn-premium { background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%); color: white; border: none; font-weight: 700; padding: 14px 30px; border-radius: 8px; cursor: pointer; transition: all 0.3s; font-size: 1rem; width: 100%; text-decoration: none; display: inline-block; }
.btn-premium:hover { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--accent-dark) 100%); color: white; transform: translateY(-2px); box-shadow: 0 5px 20px rgba(139,92,246,0.4); text-decoration: none; }
.btn-outline { background: transparent; color: var(--text-light); border: 2px solid var(--border-color); font-weight: 600; padding: 10px 20px; border-radius: 8px; cursor: pointer; transition: all 0.3s; }
        .btn-outline:hover { border-color: var(--primary); color: var(--primary); }
        .btn-outline.active { border-color: var(--accent); color: var(--accent); background: rgba(6,182,212,0.1); }
        .form-control { background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); color: var(--text-light); padding: 12px 15px; border-radius: 8px; }
.form-control:focus { background: rgba(255,255,255,0.08); border-color: var(--primary); box-shadow: 0 0 0 0.25rem rgba(139,92,246,0.25); color: var(--text-light); }
.alert { border-radius: 8px; }
.text-gradient { background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
.duration-btn { flex: 1; min-width: 100px; }
.price-display { font-size: 2rem; font-weight: 800; background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
.per-day { font-size: 0.85rem; color: var(--text-muted); }
@media (max-width: 768px) { .premium-card { padding: 30px 20px; } }
</style>
</head>
<body>
<div class="premium-card">
    <div class="text-center mb-4">
        <h2 class="text-gradient fw-bold mb-2"><?= $tierLabel[$tier] ?></h2>
        <p class="text-white-50">Choose your access duration — the longer you pick, the less you pay per day</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['flash_success'])): ?>
        <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div>
    <?php endif; ?>

    <!-- Duration Selector -->
    <form method="GET" id="durationForm" class="mb-4">
        <input type="hidden" name="tier" value="<?= htmlspecialchars($tier) ?>">
        <div class="d-flex gap-2 flex-wrap justify-content-center">
            <?php foreach ($durationOpts as $key => $opt):
                $p = $plans[$tier][$key]['price'];
                $d = $plans[$tier][$key]['days'];
                $per = round($p / $d);
                $savings = $key === 'monthly' ? 'Best value' : ($key === 'biweekly' ? '' : '');
            ?>
            <button type="submit" name="duration" value="<?= $key ?>"
                class="btn btn-outline duration-btn text-center <?= $duration === $key ? 'active' : '' ?>"
                style="<?= $key === 'monthly' ? 'border-color: var(--accent);' : '' ?>">
                <div style="font-weight: 700; font-size: 1.1rem;"><?= number_format($p) ?> <small>TZS</small></div>
                <div style="font-size: 0.9rem;"><?= $opt['label'] ?></div>
                <div class="per-day">~<?= $per ?>/day</div>
                <?php if ($savings): ?>
                <div style="font-size: 0.7rem; color: var(--accent); font-weight: 700; margin-top: 2px;"><?= $savings ?></div>
                <?php endif; ?>
            </button>
            <?php endforeach; ?>
        </div>
    </form>

    <!-- Price Summary -->
    <div class="text-center mb-4 py-3" style="background: rgba(139,92,246,0.08); border-radius: 12px;">
        <div class="price-display"><?= number_format($price) ?> TZS</div>
        <div class="text-white-50">for <?= $days ?> day<?= $days > 1 ? 's' : '' ?> access</div>
        <div class="per-day">Just ~<?= $perDay ?> TZS per day</div>
    </div>

    <!-- What's Included / Locked -->
    <?php
    $lockInfo = [
        'parlay' => ['locked' => ['Safety Rollover (not included)', 'PRO Predictions (not included)']],
        'rollover' => ['locked' => ['Parlay Premium (not included)', 'PRO Predictions (not included)']],
        'both' => ['locked' => []],
    ];
    ?>
    <div class="mb-4 p-3" style="background: rgba(6,182,212,0.05); border: 1px solid rgba(6,182,212,0.2); border-radius: 12px;">
        <h6 style="color: var(--accent); font-weight: 700; margin-bottom: 8px;"><i class="fas fa-info-circle me-1"></i> What you get with <?= $tierLabel[$tier] ?>:</h6>
        <ul class="text-white-50 small mb-0" style="padding-left: 1.2rem;">
            <?php if ($tier === 'parlay'): ?>
                <li>High-odds Parlay picks (2-19 legs, up to 30x odds)</li>
                <li>Market movement analysis</li>
                <li style="text-decoration: line-through; color: var(--text-muted);">Safety Rollover — <strong>not included</strong></li>
                <li style="text-decoration: line-through; color: var(--text-muted);">PRO Predictions — <strong>not included</strong></li>
            <?php elseif ($tier === 'rollover'): ?>
                <li>7-day Safety Cycle (1-7 picks daily)</li>
                <li>Win rate 75-85%, odds 1.18-1.30</li>
                <li>Most Corners</li>
                <li style="text-decoration: line-through; color: var(--text-muted);">Parlay Premium — <strong>not included</strong></li>
                <li style="text-decoration: line-through; color: var(--text-muted);">PRO Predictions — <strong>not included</strong></li>
            <?php else: ?>
                <li>Everything in Parlay + Rollover</li>
                <li>PRO Predictions (Top Picks + Most Corners)</li>
                <li>Priority support, best value</li>
            <?php endif; ?>
        </ul>
    </div>

    <!-- Payment Methods -->
    <div class="payment-method">
        <h5>📱 SELCOM Lipa (Till Number)</h5>
        <div class="text-center">
            <div class="qr-code">
                <?php
                if (function_exists('selcomQrExists') && function_exists('getSelcomQrImage') && selcomQrExists()) {
                    echo getSelcomQrImage('200px', '200px', 'SELCOM Lipa QR', 'rounded border border-secondary');
                } else {
                    echo '<div class="alert alert-warning p-2 mb-0">⚠️ QR code not configured. Use Till Number below.</div>';
                }
                ?>
                <p class="text-muted small mt-2 mb-0">Scan QR or enter Till Number manually</p>
            </div>
            <div class="payment-details mt-3">
                <p class="mb-2"><strong>Till Number:</strong> <code><?= defined('PAYMENT_SHORTCODE') ? PAYMENT_SHORTCODE : '70009300' ?></code></p>
                <p class="mb-2"><strong>Till Name:</strong> <code><?= defined('PAYMENT_COMPANY') ? PAYMENT_COMPANY : 'TIMOTH PETER MWAIJANDE' ?></code></p>
                <p class="mb-0"><strong>Amount:</strong> <code><?= number_format($price) ?> TZS</code></p>
            </div>
        </div>
    </div>

    <div class="payment-method">
        <h5>🏦 SELCOM Bank Transfer</h5>
        <div class="payment-details">
            <p class="mb-2"><strong>Account Number:</strong> <code><?= defined('SELCOM_BANK_ACCOUNT') ? SELCOM_BANK_ACCOUNT : '5525105325477' ?></code></p>
            <p class="mb-0 text-muted small">Transfer via mobile banking or branch. Use your phone number as reference.</p>
        </div>
    </div>

    <form method="POST">
        <div class="mb-3">
            <label class="form-label text-white-50">Payment Method *</label>
            <select name="payment_method" class="form-control" required>
                <option value="selcom_lipa">SELCOM Lipa (Till: <?= PAYMENT_SHORTCODE ?>)</option>
                <option value="selcom_bank">SELCOM Bank Transfer</option>
                <option value="mpesa">M-Pesa</option>
                <option value="Mixx">Mixx</option>
                <option value="airtel">Airtel Money</option>
                <option value="halo">HaloPesa</option>
                <option value="bank">Bank Transfer</option>
                <option value="other">Other</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label text-white-50">Payment Reference Number *</label>
            <input type="text" name="reference_number" class="form-control" placeholder="e.g., SELCOM123456 or Bank Ref" required>
            <small class="text-muted">From SELCOM SMS or Bank transfer receipt</small>
        </div>
        <div class="mb-3">
            <label class="form-label text-white-50">Phone Number</label>
            <input type="text" name="phone_number" class="form-control" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
            <small class="text-muted">Used for payment verification</small>
        </div>
        <input type="hidden" name="tier" value="<?= htmlspecialchars($tier) ?>">
        <input type="hidden" name="duration" value="<?= htmlspecialchars($duration) ?>">
        <button type="submit" class="btn btn-premium mb-3">
            🔓 Activate <?= ucfirst($tier) ?> (<?= $duration ?>)
        </button>
    </form>

    <div class="alert alert-info border-info mt-4">
        <h6 class="text-info mb-2">💡 How It Works:</h6>
        <ol class="text-white-50 small mb-0">
            <li>Select your duration — as low as <strong>~<?= $perDay ?> TZS/day</strong></li>
            <li>Send <strong><?= number_format($price) ?> TZS</strong> via the methods above</li>
            <li>Copy the <strong>Reference Number</strong> from confirmation SMS/Receipt</li>
            <li>Submit reference → Pending admin approval</li>
            <li>Access unlocks once approved — unused days roll over on renewal</li>
        </ol>
    </div>
    <div class="text-center mt-4">
        <a href="dashboard" class="text-white-50 text-decoration-none">← Back to Dashboard</a>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
