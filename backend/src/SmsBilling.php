<?php
declare(strict_types=1);
namespace Pulse;
require_once __DIR__.'/ChipeiraGateway.php';

final class SmsBilling {
    private \PDO $db;
    private ChipeiraGateway $gateway;
    public function __construct(\PDO $db, ChipeiraGateway $gateway) { $this->db=$db; $this->gateway=$gateway; }
    private function q(string $sql,array $args=[]): \PDOStatement { $q=$this->db->prepare($sql); $q->execute($args); return $q; }
    public static function segments(string $text): int {
        $basic="@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
        $extra="\f^{}\\[~]|€"; $units=0; $gsm=true;
        foreach (preg_split('//u',$text,-1,PREG_SPLIT_NO_EMPTY) as $char) {
            if (strpos($basic,$char)!==false) $units++;
            elseif (strpos($extra,$char)!==false) $units+=2;
            else { $gsm=false; break; }
        }
        if (!$gsm) $units=intdiv(strlen(iconv('UTF-8','UTF-16BE',$text)),2);
        return max(1,(int)ceil($units/($units<=($gsm?160:70)?($gsm?160:70):($gsm?153:67))));
    }
    public function estimate(string $company,array $data): array {
        $message=PanelValidation::text($data,'message',6400);
        if (preg_match_all('/./us',$message)>1600) throw new PanelError('invalid_dispatch','A mensagem pode conter até 1.600 caracteres.');
        // Mesmo renderizador da chipeira: este formulário envia telefones sem nome.
        $message=trim(preg_replace('/\s{2,}/u',' ',preg_replace('/\{\{\s*nome\s*\}\}/iu','',$message)));
        if ($message==='') throw new PanelError('invalid_dispatch','Informe o texto que será enviado.');
        $recipients=$data['recipients']??[];
        if (!is_array($recipients) || !$recipients || count($recipients)>500) throw new PanelError('invalid_dispatch','Informe até 500 destinatários.');
        foreach ($recipients as $number) if (!is_string($number) || !preg_match('/^\+55[1-9][0-9]{9,10}$/D',$number)) throw new PanelError('invalid_number','Use telefones brasileiros com +55 e DDD.');
        $account=$this->q('SELECT rate_mills,unlimited FROM sms_billing_accounts WHERE company_id=?',[$company])->fetch(\PDO::FETCH_ASSOC)?:['rate_mills'=>50,'unlimited'=>0];
        $segments=self::segments($message); $count=count(array_unique($recipients));
        $cents=$account['unlimited']?0:intdiv($segments*$count*(int)$account['rate_mills']+9,10);
        $balance=$this->q('SELECT COALESCE(SUM(amount),0) FROM balance_entries WHERE company_id=?',[$company])->fetchColumn();
        $negative=strpos((string)$balance,'-')===0;
        $parts=explode('.',ltrim((string)$balance,'-'));
        $balanceCents=((int)$parts[0]*100)+(int)str_pad($parts[1]??'',2,'0');
        return ['segments'=>$segments,'recipients'=>$count,'rate_mills'=>(int)$account['rate_mills'],'unlimited'=>(bool)$account['unlimited'],'reserved_cents'=>$cents,'balance_cents'=>$negative?-$balanceCents:$balanceCents];
    }
    private function entry(string $company,string $request,int $cents,string $suffix): void {
        if (!$cents) return;
        $amount=($cents<0?'-':'').intdiv(abs($cents),100).'.'.str_pad((string)(abs($cents)%100),2,'0',STR_PAD_LEFT);
        $this->q('INSERT INTO balance_entries(company_id,entry_key,type,amount,description,occurred_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())',[$company,hash('sha256','sms:'.$company.':'.$request.':'.$suffix),$cents<0?'debit':'reversal',$amount,($cents<0?'Reserva SMS · ':'Devolução SMS · ').$request]);
    }
    public function create(string $company,array $data): array {
        $id=PanelValidation::text($data,'request_id',80);
        if (!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$id) || ($data['consent']??false)!==true) throw new PanelError('invalid_dispatch','Confirme a autorização.');
        $payload=['op'=>'create','request_id'=>$id,'name'=>PanelValidation::text($data,'name',120),'message'=>PanelValidation::text($data,'message',6400),'recipients'=>$data['recipients']??[],'consent'=>true];
        $hash=hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $this->db->beginTransaction();
        try {
            $this->q('SELECT id FROM companies WHERE id=? FOR UPDATE',[$company]);
            $old=$this->q('SELECT * FROM sms_charges WHERE company_id=? AND request_id=? FOR UPDATE',[$company,$id])->fetch(\PDO::FETCH_ASSOC);
            if ($old && $old['payload_hash']!==$hash) throw new PanelError('dispatch_conflict','Identificador já usado para outra campanha.',409);
            if (!$old) {
                $quote=$this->estimate($company,$payload);
                if (isset($data['expected_cents']) && $data['expected_cents']!==$quote['reserved_cents']) throw new PanelError('price_changed','A tarifa mudou. Revise novamente antes de enviar.',409);
                if (!$quote['unlimited'] && $quote['balance_cents']<$quote['reserved_cents']) throw new PanelError('insufficient_balance','Saldo insuficiente. Solicite uma recarga no Financeiro.',409);
                $this->q('INSERT INTO sms_charges(company_id,request_id,payload_hash,payload,rate_mills,segments,recipients,reserved_cents) VALUES(?,?,?,?,?,?,?,?)',[$company,$id,$hash,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$quote['rate_mills'],$quote['segments'],$quote['recipients'],$quote['reserved_cents']]);
                $this->entry($company,$id,-$quote['reserved_cents'],'reserve');
            }
            $this->db->commit();
        } catch (\Throwable $e) { if($this->db->inTransaction())$this->db->rollBack(); throw $e; }
        if ($old && $old['status']==='rejected') throw new PanelError('dispatch_rejected','Esta tentativa foi recusada. Revise a campanha e inicie uma nova tentativa.',409);
        if ($old && $old['remote_id']) return $this->gateway->handle($company,'GET',['op'=>'campaign','id'=>(string)$old['remote_id']])+['replayed'=>true];
        // Timeout é ambíguo: manter reserva e repetir somente com a mesma chave remota.
        try { $result=$this->gateway->handle($company,'POST',$payload); }
        catch (PanelError $e) { if (in_array($e->httpStatus,[400,404,409,422],true)) $this->reject($company,$id); throw $e; }
        $this->q("UPDATE sms_charges SET remote_id=?,status='running' WHERE company_id=? AND request_id=? AND status='reserved'",[$result['campaign']['id'],$company,$id]);
        return $result;
    }
    public function reconcile(string $company): array {
        $rows=$this->q("SELECT * FROM sms_charges WHERE company_id=? AND status IN ('reserved','running') ORDER BY id LIMIT 100",[$company])->fetchAll(\PDO::FETCH_ASSOC);
        $settled=0; $pending=0;
        foreach($rows as $row) {
            try {
                if (!$row['remote_id']) {
                    try { $result=$this->gateway->handle($company,'POST',json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR)); }
                    catch (PanelError $e) { if (in_array($e->httpStatus,[400,404,409,422],true)) $this->reject($company,$row['request_id']); throw $e; }
                    $row['remote_id']=$result['campaign']['id'];
                    $this->q("UPDATE sms_charges SET remote_id=?,status='running' WHERE id=? AND status='reserved'",[$row['remote_id'],$row['id']]);
                }
                $result=$this->gateway->handle($company,'GET',['op'=>'campaign','id'=>(string)$row['remote_id']]);
                $campaign=$result['campaign'];
                if (!in_array($campaign['status'],['completed','cancelled'],true) || ($result['settlementReady']??false)!==true) { $pending++; continue; }
                $sent=(int)$campaign['sent_count'];
                if ($sent<0 || $sent>(int)$row['recipients']) throw new \RuntimeException('Contagem divergente');
                $cost=$row['reserved_cents']==0?0:intdiv($sent*(int)$row['segments']*(int)$row['rate_mills']+9,10);
                $this->db->beginTransaction();
                $this->q('SELECT id FROM companies WHERE id=? FOR UPDATE',[$company]);
                $current=$this->q('SELECT status FROM sms_charges WHERE id=? FOR UPDATE',[$row['id']])->fetchColumn();
                if ($current!=='settled') {
                    $this->entry($company,$row['request_id'],(int)$row['reserved_cents']-$cost,'refund');
                    $this->q("UPDATE sms_charges SET status='settled',charged_cents=?,payload='{}' WHERE id=?",[$cost,$row['id']]);
                    $settled++;
                }
                $this->db->commit();
            } catch (\Throwable $e) { if($this->db->inTransaction())$this->db->rollBack(); $pending++; }
        }
        return ['settled'=>$settled,'pending'=>$pending];
    }
    private function reject(string $company,string $request): void {
        $this->db->beginTransaction();
        try {
            $this->q('SELECT id FROM companies WHERE id=? FOR UPDATE',[$company]);
            $row=$this->q('SELECT * FROM sms_charges WHERE company_id=? AND request_id=? FOR UPDATE',[$company,$request])->fetch(\PDO::FETCH_ASSOC);
            if ($row && $row['status']==='reserved' && !$row['remote_id']) {
                $this->entry($company,$request,(int)$row['reserved_cents'],'refund');
                $this->q("UPDATE sms_charges SET status='rejected',charged_cents=0,payload='{}' WHERE id=?",[$row['id']]);
            }
            $this->db->commit();
        } catch (\Throwable $e) { if($this->db->inTransaction())$this->db->rollBack(); throw $e; }
    }
}
