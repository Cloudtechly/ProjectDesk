<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class ProvisionAdmin extends Command
{
    protected $signature = 'project-desk:provision-admin
        {--name= : اسم مدير النظام}
        {--email= : البريد الإلكتروني}
        {--password= : كلمة المرور؛ متاحة فقط في local/testing للأتمتة}';

    protected $description = 'إنشاء أول حساب مدير داخلي بأمان';

    public function handle(): int
    {
        if (User::query()->where('global_role', 'admin')->where('status', 'active')->whereNull('archived_at')->exists()) {
            $this->info('يوجد مدير نظام نشط بالفعل؛ لم يُنشأ حساب إضافي.');

            return self::SUCCESS;
        }

        $name = trim((string) ($this->option('name') ?: $this->ask('اسم مدير النظام')));
        $email = mb_strtolower(trim((string) ($this->option('email') ?: $this->ask('البريد الإلكتروني'))));
        $passwordOption = $this->option('password');

        if (is_string($passwordOption) && ! app()->environment(['local', 'testing'])) {
            $this->error('لا يُسمح بتمرير كلمة المرور كخيار خارج بيئة local/testing.');

            return self::FAILURE;
        }

        $password = is_string($passwordOption) ? $passwordOption : (string) $this->secret('كلمة المرور');
        $validation = Validator::make(compact('name', 'email', 'password'), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
        ]);

        if ($validation->fails()) {
            foreach ($validation->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'global_role' => 'admin',
            'status' => 'active',
        ]);

        $user->forceFill([
            'email_verified_at' => now(),
        ])->save();

        $this->info("تم إنشاء مدير النظام: {$user->email}");

        return self::SUCCESS;
    }
}
