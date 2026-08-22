<?php

namespace App\Jobs;

use App\Mail\RegisterMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Mail;

class SsendRegisterEmailJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public $email;

    public $name;
    public $password;
    public function __construct($email,$name,$password)
    {
        $this->email=$email;
        $this->name=$name;
        $this->password=$password;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Mail::to($this->email)->send(new RegisterMail($this->email,$this->name,$this->password));
    }
}
