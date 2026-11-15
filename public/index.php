<?php
declare(strict_types=1);
require dirname(__DIR__).'/core.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
$public=array_key_exists('s',$_GET);
$loan=null; $admin=false; $error=''; $csrf='';
function go(string $q=''): never { header('Location: ./'.$q,true,303); exit; }
function field(string $key): string { return is_string($_POST[$key]??null)?$_POST[$key]:''; }
if ($public) {
    $loan=ledger('WHERE l.token=?',[is_string($_GET['s'])?$_GET['s']:''])[0]??null;
    if (!$loan) http_response_code(404);
    if ($_SERVER['REQUEST_METHOD']!=='GET' && $_SERVER['REQUEST_METHOD']!=='HEAD') {http_response_code(405);exit;}
} else {
    session_name('billing_'.substr(hash('sha256',__DIR__),0,12));
    session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off','samesite'=>'Lax']);
    session_start();
    $hash=query('SELECT password FROM admin WHERE id=1')->fetchColumn();
    $admin=$hash && hash_equals(hash('sha256',$hash),$_SESSION['auth']??'');
    $csrf=$_SESSION['csrf']??=bin2hex(random_bytes(24));
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        try {
            if (!hash_equals($csrf,field('csrf'))) throw new DomainException('Refresh the page and try again.');
            $op=field('op');
            if ($op==='login') {
                if (!$hash || !password_verify(field('password'),$hash)) { usleep(350000); throw new DomainException('Unable to sign in. Check your admin password.'); }
                session_regenerate_id(true); $_SESSION=['auth'=>hash('sha256',$hash)]; go();
            }
            if (!$admin) {http_response_code(403); exit;}
            if ($op==='logout') {session_destroy(); go();}
            if ($op==='client') {
                query('INSERT INTO clients(name) VALUES(?)',[labelText(field('name'))]); go('?clients');
            }
            if ($op==='details') {
                $id=(int)field('id');
                savePaymentDetails($id,field('month'),field('remarks'),$_FILES['proof']??null,field('remove')==='1');
                go('?loan='.$id.'#p-'.rawurlencode(field('month')));
            }
            if ($op==='payment') {
                query('UPDATE payments SET paid=? WHERE loan=? AND month=?',[(int)(field('paid')==='1'),(int)field('id'),field('month')]);
                go('?loan='.(int)field('id'));
            }
            if ($op==='save') {
                $id=(int)field('id'); $client=(int)field('client'); $title=labelText(field('title'));
                $first=field('first'); $day=firstPaymentDay($first);
                $schedule=paymentMonths($first,field('terms')); $cents=amount(field('amount'));
                if (!query('SELECT id FROM clients WHERE id=?',[$client])->fetchColumn()) throw new DomainException('Add or select a client first.');
                db()->beginTransaction();
                if ($id) {
                    if (!query('SELECT id FROM loans WHERE id=?',[$id])->fetchColumn()) throw new DomainException('Loan unavailable.');
                    foreach (query('SELECT p.month FROM payments p LEFT JOIN payment_details d USING(loan,month) WHERE p.loan=? AND (p.paid=1 OR d.mime IS NOT NULL OR length(d.remarks)>0)',[$id]) as $row) {
                        if (!in_array($row['month'],$schedule,true)) throw new DomainException('The term must include months with a paid installment, proof or remarks.');
                    }
                    query('UPDATE loans SET client=?,title=?,payment_day=? WHERE id=?',[$client,$title,$day,$id]);
                    query('DELETE FROM payments WHERE loan=? AND (month<? OR month>?)',[$id,$schedule[0],end($schedule)]);
                } else {
                    query('INSERT INTO loans(client,title,token,payment_day) VALUES(?,?,?,?)',[$client,$title,bin2hex(random_bytes(32)),$day]);
                    $id=(int)db()->lastInsertId();
                }
                foreach ($schedule as $m) query('INSERT INTO payments(loan,month,cents) VALUES(?,?,?) ON CONFLICT(loan,month) DO UPDATE SET cents=excluded.cents WHERE payments.paid=0',[$id,$m,$cents]);
                db()->commit(); go('?loan='.$id);
            }
        } catch (DomainException $ex) {
            if (db()->inTransaction()) db()->rollBack(); $error=$ex->getMessage();
        } catch (PDOException $ex) {
            if (db()->inTransaction()) db()->rollBack(); $error='Unable to save. Please try again.';
        }
    }
    if ($admin && isset($_GET['loan'])) {
        $loan=ledger('WHERE l.id=?',[(int)$_GET['loan']])[0]??null;
        if (!$loan) {http_response_code(404); $error='Loan unavailable.';}
    }
}
if (isset($_GET['proof'])) {
    $month=is_string($_GET['proof']) ? $_GET['proof'] : '';
    $file=$loan ? query('SELECT proof,mime FROM payment_details WHERE loan=? AND month=? AND mime IS NOT NULL',[$loan['id'],$month])->fetch() : null;
    if (!$file) { http_response_code(404); exit; }
    $ext=['image/png'=>'png','image/jpeg'=>'jpg','application/pdf'=>'pdf'][$file['mime']];
    header('Content-Type: '.$file['mime']);
    header('X-Content-Type-Options: nosniff');
    $disposition=isset($_GET['preview']) ? 'inline' : 'attachment';
    header('Content-Disposition: '.$disposition.'; filename="payment-'.$month.'.'.$ext.'"');
    echo $file['proof'];
    exit;
}
function hidden(string $op): void {
    global $csrf; echo '<input type="hidden" name="csrf" value="'.e($csrf).'"><input type="hidden" name="op" value="'.e($op).'">';
}
function stats(int $total,int $received): void { ?>
<div class="stats"><?php foreach (['Total amount'=>$total,'Amount paid'=>$received,'Balance'=>$total-$received] as $key=>$value): ?><div><span><?=e($key)?></span><strong><?=money($value)?></strong></div><?php endforeach ?></div>
<?php }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?=e($loan['title']??'Loan ledger')?> · John Rey Simple Billing System</title><link rel="stylesheet" href="style.css"><script defer src="app.js"></script></head>
<body><header><div class="bar"><b class="brand"><i aria-hidden="true">▤</i> John Rey Simple Billing System</b><?php if ($admin): ?><nav><a href="./">Loans</a><a href="?clients">Clients</a></nav><form class="right" method="post"><?php hidden('logout') ?><button>Sign out</button></form><?php else: ?><span class="right quiet"><?=$public?'Borrower statement':'Billing management'?></span><?php endif ?></div></header><main>
<?php if ($error): ?><p role="alert"><?=e($error)?></p><?php endif ?>
<?php if ($public && !$loan): ?><h1>Statement unavailable</h1><p>Ask the administrator for your billing link.</p>
<?php elseif (!$public && !$admin): ?><section class="login panel"><div class="eyebrow">Billing management</div><h1>Admin sign in</h1><form method="post"><?php hidden('login') ?><label>Admin password<input required type="password" name="password" autocomplete="current-password"></label><button class="primary">Sign in</button></form></section>
<?php elseif ($admin && (isset($_GET['new']) || ($loan && isset($_GET['edit'])))):
$clients=query('SELECT * FROM clients ORDER BY name,id')->fetchAll(); $defaultAmount='';
if ($loan) $defaultAmount=number_format((int)query('SELECT cents FROM payments WHERE loan=? ORDER BY paid,month LIMIT 1',[$loan['id']])->fetchColumn()/100,2,'.','');
$val=fn($key,$default)=>e($error && isset($_POST[$key])?field($key):$default); ?>
<a class="back" href="<?=$loan?'?loan='.$loan['id']:'./'?>">← Back</a><section class="panel editor"><h1><?=$loan?'Edit loan':'New loan'?></h1>
<?php if (!$clients): ?><p>Add a borrower before creating their loan.</p><a class="primary" href="?clients">Add client</a><?php else: ?>
<form method="post"><?php hidden('save') ?><input type="hidden" name="id" value="<?=e($loan['id']??0)?>">
<label>Loan owner<select name="client" required><?php foreach ($clients as $c): ?><option value="<?=$c['id']?>" <?=$c['id']==($error?field('client'):($loan['client']??$_GET['client']??0))?'selected':''?>><?=e($c['name'])?></option><?php endforeach ?></select></label>
<label>Loan name<input required maxlength="120" name="title" value="<?=$val('title',$loan['title']??'')?>"></label>
<label>Monthly payment (PHP)<input required type="number" min="0.01" max="999999999.99" step=".01" name="amount" value="<?=$val('amount',$defaultAmount)?>"></label>
<div class="pair"><label>First payment date<input required type="date" name="first" value="<?=$val('first',$loan ? ($loan['payment_day'] ? dueDate($loan['first'],$loan['payment_day']) : '') : date('Y-m-d'))?>"></label><label>Payment term (months)<input required type="number" min="1" max="600" step="1" name="terms" value="<?=$val('terms',$loan['terms']??'')?>"></label></div><p class="quiet">Payments repeat on the same day each month. Shorter months use their last day.</p><p id="term" class="quiet" aria-live="polite"></p>
<?php if ($loan): ?><p class="quiet">Amount changes apply to unpaid months. Paid installments keep their recorded amount.</p><?php endif ?><button class="primary">Save loan</button></form><?php endif ?></section>
<?php elseif ($loan): $rows=query('SELECT p.*, d.mime, d.remarks FROM payments p LEFT JOIN payment_details d USING(loan,month) WHERE p.loan=? ORDER BY p.month',[$loan['id']])->fetchAll(); ?>
<?php if ($admin): ?><a class="back" href="./">← Loan ledger</a><?php endif ?>
<section class="hero"><div class="eyebrow">Billing details</div><h1><?=e($loan['title'])?></h1><p>Loan owner · <b><?=e($loan['name'])?></b></p><?php stats($loan['total'],$loan['received']) ?></section>
<section class="sectionhead"><div><h2>Payment schedule</h2><p class="quiet"><?=$loan['terms']?> monthly <?=($loan['terms']==1?'payment':'payments')?> · <?=paymentDateLabel($loan['first'],$loan['payment_day'])?> – <?=paymentDateLabel($loan['last'],$loan['payment_day'])?></p></div><?php if ($admin): ?><div class="actions"><a class="button" href="?loan=<?=$loan['id']?>&amp;edit">Edit loan</a><button class="primary" id="share" data-link="?s=<?=e($loan['token'])?>">Share link</button></div><?php endif ?></section>
<?php if ($admin && $loan['payment_day'] === null): ?><p class="quiet">Edit this loan to set its monthly payment day.</p><?php endif ?>
<div id="sharebox" hidden><label>Borrower link<input readonly></label><a target="_blank" rel="noreferrer">Open statement ↗</a><span aria-live="polite"></span></div>
<div class="panel tablebox"><table><thead><tr><th>Payment</th><th>Amount</th><th>Status</th><th>Proof of payment</th></tr></thead><tbody><?php foreach ($rows as $i=>$r): ?><tr id="p-<?=e($r['month'])?>"><td><strong>Payment <?=$i+1?></strong><small><?=paymentDateLabel($r['month'],$loan['payment_day'])?></small></td><td><?=money($r['cents'])?></td><td><?php $status=$r['paid']?'Paid':'Unpaid'; if ($admin): ?><form method="post"><?php hidden('payment') ?><input type="hidden" name="id" value="<?=$loan['id']?>"><input type="hidden" name="month" value="<?=e($r['month'])?>"><input type="hidden" name="paid" value="<?=$r['paid']?0:1?>"><button class="pill p<?=$r['paid']?>" aria-label="Mark Payment <?=$i+1?> as <?=$r['paid']?'unpaid':'paid'?>" title="Mark as <?=$r['paid']?'unpaid':'paid'?>"><?=$status?> ⇄</button></form><?php else: ?><span class="pill p<?=$r['paid']?>"><?=$status?></span><?php endif ?></td>
<td class="proof-cell">
<?php if ($r['mime']):
    $proofUrl='?'.http_build_query(($public ? ['s'=>$loan['token']] : ['loan'=>$loan['id']]) + ['proof'=>$r['month']]);
?>
<div class="proof-actions">
<a class="proof-button" href="<?=e($proofUrl.'&preview=1')?>" target="_blank" rel="noopener" <?= $r['mime']==='application/pdf' ? '' : 'data-image-proof' ?> data-payment="<?=$i+1?>">View proof</a>
</div>
<?php endif ?>
<?php if (strlen($r['remarks']??'')): ?><small class="remarks"><?=e($r['remarks'])?></small><?php elseif (!$r['mime'] && !$admin): ?>—<?php endif ?>
<?php if ($admin): $retry=$error && field('op')==='details' && field('month')===$r['month']; ?>
<details <?=$retry?'open':''?>><summary><?=$r['mime'] || strlen($r['remarks']??'')?'Edit':'Add'?> proof or remarks</summary>
<form method="post" enctype="multipart/form-data">
<?php hidden('details') ?><input type="hidden" name="id" value="<?=$loan['id']?>"><input type="hidden" name="month" value="<?=e($r['month'])?>">
<label>Proof file (optional)<input type="file" name="proof" accept="image/jpeg,image/png,application/pdf"></label><small>JPEG, PNG or PDF · Up to 2 MB</small>
<?php if ($r['mime']): ?><label class="remove-proof"><input type="checkbox" name="remove" value="1"> Remove current proof</label><?php endif ?>
<label>Payment remarks (optional)<textarea name="remarks" rows="3" maxlength="2000"><?=e($retry?field('remarks'):($r['remarks']??''))?></textarea></label>
<button>Save details</button></form></details>
<?php endif ?></td></tr><?php endforeach ?></tbody></table></div>
<?php elseif (isset($_GET['clients'])): $clients=query('SELECT c.*,count(l.id) loans FROM clients c LEFT JOIN loans l ON l.client=c.id GROUP BY c.id ORDER BY c.name,c.id')->fetchAll(); ?>
<h1>Clients</h1><div class="panel"><form class="inline" method="post"><?php hidden('client') ?><label>Borrower name<input required name="name" maxlength="120" placeholder="Full name"></label><button class="primary">Add client</button></form></div>
<section class="sectionhead"><h2>Client directory</h2></section><div class="panel"><?php if (!$clients): ?>Your clients will appear here.<?php endif; foreach ($clients as $c): ?><div class="item"><div><strong><?=e($c['name'])?></strong><small><?=$c['loans']?> loan<?=$c['loans']==1?'':'s'?></small></div><a class="button" href="?new&amp;client=<?=$c['id']?>">New loan</a></div><?php endforeach ?></div>
<?php else: $loans=ledger(); ?><section class="hero"><div class="eyebrow">Billing overview</div><h1>Loan ledger</h1><?php stats(array_sum(array_column($loans,'total')),array_sum(array_column($loans,'received'))) ?></section>
<section class="sectionhead"><h2>Loans <span class="count"><?=count($loans)?></span></h2><div class="actions"><a class="button" href="?clients">Manage clients</a><a class="primary" href="?new">+ New loan</a></div></section>
<div class="panel"><?php if (!$loans): ?><div class="empty"><h3>No loans yet</h3><p>Add a client to start their first payment schedule.</p><a href="?clients">Add a client →</a></div><?php endif; foreach ($loans as $l): ?><a class="item loan" href="?loan=<?=$l['id']?>"><div><strong><?=e($l['title'])?></strong><small><?=e($l['name'])?></small></div><div class="end"><strong><?=money($l['total']-$l['received'])?></strong><small><?=$l['total']==$l['received']?'Paid in full':'Outstanding balance'?> ›</small></div></a><?php endforeach ?></div><?php endif ?>
<?php if ($loan): ?>
<dialog id="proof-preview" aria-labelledby="proof-title">
<div class="preview-heading"><h2 id="proof-title">Proof of payment</h2><form method="dialog"><button autofocus>Close</button></form></div>
<img alt="Proof of payment">
</dialog>
<?php endif ?>
</main></body></html>
