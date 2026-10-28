<?php
declare(strict_types=1);

// Run from the hosting control panel's scheduled tasks. Never expose this as a web route.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$configFile=__DIR__.'/nyathi-config.php';
if (!is_file($configFile)) { fwrite(STDERR,"Nyathi Tours backend is not configured.\n"); exit(1); }
$config=require $configFile;
try {
    $db=new PDO('mysql:host='.$config['db_host'].';dbname='.$config['db_name'].';charset=utf8mb4',$config['db_user'],$config['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $lock=$db->query("SELECT GET_LOCK('nyathi-reminder-worker',0)")->fetchColumn();
    if ((int)$lock!==1) exit(0);
    $jobs=$db->query("SELECT r.id,r.reminder_type,b.client_name,b.email,b.departure_date,b.seats,b.pickup,b.package_label,t.name AS trip_name,i.invoice_number,i.total,i.deposit_required FROM reminder_jobs r JOIN bookings b ON b.id=r.booking_id JOIN trips t ON t.id=b.trip_id LEFT JOIN invoices i ON i.booking_id=b.id WHERE r.status IN ('queued','failed') AND r.attempts<3 AND r.scheduled_at<=NOW() ORDER BY r.scheduled_at,r.id LIMIT 25")->fetchAll();
    $from=filter_var($config['mail_from']??'',FILTER_VALIDATE_EMAIL);
    $base=rtrim((string)($config['base_url']??''),'/');
    if (!$from || !preg_match('#^https://#i',$base)) throw new RuntimeException('Configure an HTTPS base_url and mail_from address.');
    foreach($jobs as $job){
        $name=htmlspecialchars($job['client_name'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $trip=htmlspecialchars($job['trip_name'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $date=htmlspecialchars((new DateTime($job['departure_date']))->format('j F Y'),ENT_QUOTES,'UTF-8');
        $pickup=htmlspecialchars($job['pickup'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $invoice=htmlspecialchars((string)($job['invoice_number']??''),ENT_QUOTES,'UTF-8');
        $amount=number_format((float)($job['total']??0),2,'.',',');
        if($job['reminder_type']==='trip_soon'){
            $subject='Nyathi Tours · your trip is coming up';
            $headline='Your trip is coming up';
            $message='A quick reminder that your Nyathi Tours departure is one week away. Please contact us if any of your travel details have changed.';
        }else{
            $subject='Nyathi Tours · booking accepted';
            $headline='Your booking is accepted';
            $message='Thank you for choosing Nyathi Tours. Your booking has been accepted. Please review the trip and invoice details below.';
        }
        $body='<!doctype html><html><body style="margin:0;background:#f4f0e7;font-family:Arial,sans-serif;color:#14233d"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center" style="padding:24px"><table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#fffdf8;border:1px solid #d9d4ca"><tr><td style="background:#14233d;padding:18px 24px"><img src="'.htmlspecialchars($base.'/nyathi-logo.jpg',ENT_QUOTES,'UTF-8').'" alt="Nyathi Tours" width="145" style="display:block"></td></tr><tr><td style="height:5px;background:linear-gradient(90deg,#d9362b 0 33%,#1c61c2 33% 100%)"></td></tr><tr><td style="padding:28px 30px"><p style="font-size:12px;line-height:1.7">Hi '.$name.',</p><h1 style="font:normal 27px Georgia,serif">'.$headline.'</h1><p style="font-size:14px;line-height:1.7">'.$message.'</p><p style="font-size:14px;line-height:1.8"><b>'.$trip.'</b><br>Departure: '.$date.'<br>Pickup: '.$pickup.'<br>Travellers: '.(int)$job['seats'].($invoice?'<br>Invoice '.$invoice.' · R'.$amount:'').'</p><p style="font-size:13px;line-height:1.7">For questions, call or WhatsApp <a href="tel:+27733047351" style="color:#1c61c2">+27 73 304 7351</a>.</p><p style="font-size:13px">We look forward to welcoming you.</p></td></tr><tr><td style="border-top:1px solid #d9d4ca;padding:14px 30px;color:#737b84;font-size:11px">Nyathi Tours · Nelspruit, South Africa · musa@nyathitours.co.za</td></tr></table></td></tr></table></body></html>';
        $headers="MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: Nyathi Tours <{$from}>\r\n";
        $sent=filter_var($job['email'],FILTER_VALIDATE_EMAIL)&&mail($job['email'],$subject,$body,$headers);
        $q=$db->prepare('UPDATE reminder_jobs SET status=?,sent_at=IF(?,NOW(),sent_at),attempts=attempts+1 WHERE id=? AND status IN (\'queued\',\'failed\')');
        $q->execute([$sent?'sent':'failed',$sent?1:0,$job['id']]);
    }
    $db->query("SELECT RELEASE_LOCK('nyathi-reminder-worker')");
} catch(Throwable $e) { if(isset($db)){$db->query("SELECT RELEASE_LOCK('nyathi-reminder-worker')");} error_log('Nyathi reminder worker error: '.$e->getMessage());fwrite(STDERR,"Reminder worker failed. Check the server error log.\n");exit(1); }
