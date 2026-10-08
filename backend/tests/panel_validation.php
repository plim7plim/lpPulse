<?php
declare(strict_types=1);
require_once __DIR__.'/../src/PanelValidation.php';
use Pulse\PanelValidation as V;
use Pulse\PanelError;
$checks=0;
function expect($actual,$expected): void { global $checks; ++$checks; if ($actual!==$expected) throw new RuntimeException('Unexpected validation result.'); }
function reject(callable $action,string $code): void { global $checks; ++$checks; try { $action(); } catch (PanelError $error) { if ($error->errorCode===$code) return; throw $error; } throw new RuntimeException('Expected rejection: '.$code); }
expect(V::id('123456789012345678'),'123456789012345678');
reject(fn()=>V::id('1 OR 1=1'),'invalid_id');
reject(fn()=>V::id(-1),'invalid_id');
reject(fn()=>V::id(1.0),'invalid_id');
expect(V::amount('100.00'),'100.00');
expect(V::amount('0.01'),'0.01');
reject(fn()=>V::amount('0.00'),'invalid_amount');
reject(fn()=>V::amount(100.0),'invalid_amount');
reject(fn()=>V::amount('1e3'),'invalid_amount');
reject(fn()=>V::amount('100,00'),'invalid_amount');
reject(fn()=>V::amount('-2.00'),'invalid_amount');
expect(V::email(['email'=>' TEST@EXAMPLE.COM '],'email'),'test@example.com');
reject(fn()=>V::email(['email'=>'broken'],'email'),'invalid_email');
reject(fn()=>V::email(['email'=>'ação@example.com'],'email'),'invalid_email');
expect(V::text(['body'=>"  Olá\ncliente  "],'body',100),"Olá\ncliente");
reject(fn()=>V::text(['body'=>"\x00"],'body',100),'invalid_field');
reject(fn()=>V::text(['body'=>['unexpected']],'body',100),'invalid_field');
reject(fn()=>V::choice(['role'=>'owner'],'role',['viewer','operator']),'invalid_choice');
expect(json_decode(V::details(['destination'=>'Brasil','lines'=>2,'portability'=>false]),true),['destination'=>'Brasil','lines'=>2,'portability'=>false]);
reject(fn()=>V::details(['nested'=>['status'=>'approved']]),'invalid_details');
reject(fn()=>V::details(['bad key'=>'value']),'invalid_details');
reject(fn()=>V::details([]),'invalid_details');
reject(fn()=>V::noAttachments(['attachments'=>[['name'=>'proof.png']]]),'uploads_unavailable');
V::noAttachments(['attachments'=>[]]);
echo "Panel validation: $checks checks passed.\n";
