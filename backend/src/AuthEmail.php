<?php
declare(strict_types=1);
namespace Pulse;

final class AuthEmail {
    private array $config;
    public function __construct(array $config) { $this->config=$config; }
    public function ready(): void {
        if (($this->config['local_test']??false)===true && ($this->config['auth_mail']['transport']??'')==='file') {
            $directory=realpath($this->config['auth_mail']['directory']??'');
            $root=strtolower(str_replace('\\','/',realpath(__DIR__.'/../..')).'/');
            if ($directory && is_writable($directory) && strpos(strtolower(str_replace('\\','/',$directory)).'/',$root)!==0) return;
        }
        if (($this->config['auth_mail']['transport']??'')==='mail' && filter_var($this->config['auth_mail']['from']??'',FILTER_VALIDATE_EMAIL) && !preg_match('/[\r\n]/',$this->config['auth_mail']['from']) && function_exists('mail')) return;
        throw new PanelError('email_not_configured','O envio de e-mail ainda precisa ser configurado. Entre em contato com a equipe.',503);
    }
    public function send(string $to,string $token,string $purpose): void {
        $this->ready();
        $link=rtrim($this->config['api_origin'],'/').'/paginas/acesso.html#'.$purpose.'='.$token;
        $subject=$purpose==='verify'?'Confirme seu cadastro no Pulse':'Redefina sua senha do Pulse';
        $body=($purpose==='verify'?"Para confirmar seu e-mail e definir sua senha":"Para definir uma nova senha").", abra o link abaixo:\n\n".$link."\n\nO link vale por 30 minutos e só pode ser usado uma vez.\nSe você não fez esta solicitação, ignore este e-mail.\n\nPulse";
        if (($this->config['auth_mail']['transport']??'')==='file' && ($this->config['local_test']??false)===true) {
            $file=rtrim($this->config['auth_mail']['directory'],'/\\').'/'.bin2hex(random_bytes(16)).'.eml';
            if (file_put_contents($file,"To: $to\nSubject: $subject\n\n$body",LOCK_EX)===false) throw new \RuntimeException('Email file unavailable');
            return;
        }
        $headers=['From'=>$this->config['auth_mail']['from'],'MIME-Version'=>'1.0','Content-Type'=>'text/plain; charset=UTF-8'];
        if (!mail($to,'=?UTF-8?B?'.base64_encode($subject).'?=',$body,$headers)) throw new \RuntimeException('Email delivery unavailable');
    }
}
