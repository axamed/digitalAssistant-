<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Services\AssistantService;

Auth::requireUser();

$query  = trim($_GET['q'] ?? '');
$result = $query !== '' ? AssistantService::ask($query) : null;

render('layouts/app_header', ['title' => 'Digital Civil Registry Assistant']);
?>
<div class="card" style="max-width: 800px; margin: 0 auto;">
  <h2>Digital Assistant for Civil Registration</h2>
  <p class="muted">
    This knowledge assistant explains official registration rules, fee schedules, required fields, approval workflows, and Banadir district rules.
    It operates in read-only informational mode and never modifies civil records.
  </p>

  <form method="GET" style="display:flex; gap:10px; margin:1.5rem 0;">
    <input type="text" name="q" value="<?= e($query) ?>" placeholder="Ask about birth, marriage, death, divorce, fees, or approvals..." class="form-control" style="flex:1; padding:10px;" required>
    <button type="submit" class="btn btn-primary" style="padding:10px 20px;">Ask Assistant</button>
  </form>

  <?php if ($result): ?>
    <div style="background:var(--brass-faint); border-left:4px solid var(--brass); padding:1.2rem; border-radius:4px; margin-top:1.5rem;">
      <h3 style="margin-top:0;"><?= e($result['title']) ?></h3>
      <p style="line-height:1.6; margin-bottom:0; font-size:15px;"><?= e($result['answer']) ?></p>
    </div>
  <?php else: ?>
    <div style="margin-top:1.5rem;">
      <h4>Common Inquiries:</h4>
      <ul>
        <li><a href="assistant.php?q=birth+registration+fee+and+photo">What is required for registering a birth?</a></li>
        <li><a href="assistant.php?q=marriage+registration+and+witnesses">What details are needed for a marriage record?</a></li>
        <li><a href="assistant.php?q=death+registration+guarantor">What is required for a death registration?</a></li>
        <li><a href="assistant.php?q=how+does+digital+signature+approval+work">How does supervisor digital signature approval work?</a></li>
        <li><a href="assistant.php?q=banadir+districts">Which districts are covered in Banadir Region?</a></li>
      </ul>
    </div>
  <?php endif; ?>
</div>
<?php render('layouts/app_footer'); ?>
