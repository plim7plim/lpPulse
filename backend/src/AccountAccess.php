<?php
declare(strict_types=1);
namespace Pulse;
require_once __DIR__.'/PanelValidation.php';
require_once __DIR__.'/AuthEmail.php';

final class AccountAccess {
    private \PDO $db; private string $secret; private AuthEmail $mail;
    public function __construct(\PDO $db,array $config,?AuthEmail $mail=null) { $this->db=$db; $this->secret=$config['api_secret'];$this->mail=$mail??new AuthEmail($config); }
    private function q(string $sql,array $args=[]): \PDOStatement { $q=$this->db->prepare($sql);$q->execute($args);return $q; }
    private function throttle(string $action,string $ip,string $email): void {
        foreach(['ip:'.$ip,'email:'.$email] as $scope) {
            $key=hash_hmac('sha256','account:'.$action.':'.$scope,$this->secret);
            $this->q('INSERT IGNORE INTO auth_login_attempts(attempt_key,attempts,window_started_at) VALUES(?,0,UTC_TIMESTAMP())',[$key]);
            $this->db->beginTransaction();
            try {
                $row=$this->q('SELECT attempts,window_started_at FROM auth_login_attempts WHERE attempt_key=? FOR UPDATE',[$key])->fetch(\PDO::FETCH_ASSOC);
                $fresh=strtotime($row['window_started_at'].' UTC')<time()-900; $count=$fresh?0:(int)$row['attempts'];
                if($count>=5)throw new PanelError('rate_limited','Aguarde 15 minutos antes de tentar novamente.',429);
                $this->q('UPDATE auth_login_attempts SET attempts=?,window_started_at=? WHERE attempt_key=?',[$count+1,$fresh?gmdate('Y-m-d H:i:s'):$row['window_started_at'],$key]);$this->db->commit();
            }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        }
    }
    public function handle(string $action,array $data,string $ip): array {
        if(!in_array($action,['register','forgot_password','verify_email','reset_password'],true))throw new PanelError('not_found','Recurso não encontrado.',404);
        if(in_array($action,['register','forgot_password'],true)) {
            $email=PanelValidation::email($data,'email');$this->throttle($action,$ip,$email);$this->mail->ready();
            $name=$action==='register'?PanelValidation::text($data,'name',191):'';
            $company=$action==='register'?PanelValidation::text($data,'company',191):'';
            $token=bin2hex(random_bytes(32));$table=$action==='register'?'email_verification_tokens':'password_reset_tokens';
            $this->db->beginTransaction();
            try {
                $user=$this->q('SELECT id,status,deleted_at FROM users WHERE email=? FOR UPDATE',[$email])->fetch(\PDO::FETCH_ASSOC);
                $eligible=false;
                if($action==='register' && !$user) {
                    $this->q("INSERT INTO companies(name,contact_email,status) VALUES(?,?,'pending')",[$company,$email]);$companyId=$this->db->lastInsertId();
                    $this->q("INSERT INTO users(name,email,password_hash,status) VALUES(?,?,?,'pending')",[$name,$email,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT)]);$user=['id'=>$this->db->lastInsertId(),'status'=>'pending','deleted_at'=>null];
                    $this->q("INSERT INTO company_users(company_id,user_id,role,status) VALUES(?,?,'owner','active')",[$companyId,$user['id']]);$eligible=true;
                } elseif($user && !$user['deleted_at']) {
                    $eligible=$action==='forgot_password'?$user['status']==='active':($user['status']==='pending' && (bool)$this->q('SELECT id FROM email_verification_tokens WHERE user_id=? LIMIT 1',[$user['id']])->fetchColumn());
                }
                if($eligible) {
                    $this->q('UPDATE '.$table.' SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL',[$user['id']]);
                    $this->q('INSERT INTO '.$table.'(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE))',[$user['id'],hash('sha256',$token)]);
                }
                $this->db->commit();
            }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
            if($eligible) {
                try{$this->mail->send($email,$token,$action==='register'?'verify':'reset');}
                catch(\Throwable $e){error_log('Pulse account email delivery failed');}
            }
            return ['message'=>$action==='register'?'Se o e-mail puder receber um novo cadastro, enviaremos um link para confirmar a conta e definir a senha.':'Se houver uma conta ativa com este e-mail, enviaremos um link para redefinir a senha.'];
        }
        $token=$data['token']??''; $purpose=$action==='verify_email'?'verify':'reset';
        $this->throttle($action,$ip,is_string($token)?hash('sha256',$token):'invalid');
        if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))throw new PanelError('invalid_token','Link inválido ou expirado. Solicite um novo link.',422);
        $password=$data['password']??'';
        if(!is_string($password)||!preg_match('//u',$password)||preg_match_all('/./us',$password)<12||strlen($password)>72||strpos($password,"\0")!==false)throw new PanelError('invalid_password','Use pelo menos 12 caracteres. Se a senha for muito longa, escolha uma frase mais curta.');
        if(!is_string($data['password_confirmation']??null)||!hash_equals($password,$data['password_confirmation']))throw new PanelError('password_mismatch','As senhas precisam ser iguais.');
        $table=$purpose==='verify'?'email_verification_tokens':'password_reset_tokens';
        $initial=$this->q('SELECT user_id FROM '.$table.' WHERE token_hash=?',[hash('sha256',$token)])->fetchColumn();
        if(!$initial)throw new PanelError('invalid_token','Link inválido ou expirado. Solicite um novo link.',422);
        $hash=password_hash($password,PASSWORD_DEFAULT);
        $this->db->beginTransaction();
        try {
            $user=$this->q('SELECT id,status,deleted_at FROM users WHERE id=? FOR UPDATE',[$initial])->fetch(\PDO::FETCH_ASSOC);
            $row=$this->q('SELECT id FROM '.$table.' WHERE token_hash=? AND used_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE',[hash('sha256',$token)])->fetch(\PDO::FETCH_ASSOC);
            if(!$row || !$user || $user['deleted_at'] || $user['status']!==($purpose==='verify'?'pending':'active'))throw new PanelError('invalid_token','Link inválido ou expirado. Solicite um novo link.',422);
            $this->q("UPDATE users SET password_hash=?,status='active',email_verified_at=COALESCE(email_verified_at,UTC_TIMESTAMP()) WHERE id=?",[$hash,$initial]);
            // Cadastro novo ativa somente sua empresa pendente, sem alterar empresas suspensas.
            if($purpose==='verify')$this->q("UPDATE companies c JOIN company_users cu ON cu.company_id=c.id SET c.status='active' WHERE cu.user_id=? AND cu.role='owner' AND c.status='pending'",[$initial]);
            foreach(['password_reset_tokens','email_verification_tokens'] as $tokens)$this->q('UPDATE '.$tokens.' SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL',[$initial]);
            $this->q('UPDATE auth_sessions SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND revoked_at IS NULL',[$initial]);
            $this->db->commit();return ['message'=>$purpose==='verify'?'Conta confirmada. Entre com sua nova senha.':'Senha atualizada. Entre novamente com sua nova senha.'];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
