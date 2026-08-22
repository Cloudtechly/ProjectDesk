import { Link } from '@inertiajs/react';
import { LanguageSwitcher, useLocale } from '@/i18n';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { direction, t } = useLocale();

    return (
        <div
            className="relative isolate min-h-svh overflow-hidden bg-[#f3f6f7] text-[#102432]"
            dir={direction}
        >
            <a
                href="#auth-main"
                className="absolute start-4 top-4 z-50 -translate-y-24 rounded-lg bg-[#16c8ce] px-4 py-3 font-bold text-[#102d3d] shadow-lg transition-transform focus:translate-y-0 focus:outline-3 focus:outline-offset-2 focus:outline-[#102d3d]"
            >
                {t('shell.skipToMain')}
            </a>

            <div className="absolute end-4 top-4 z-40">
                <LanguageSwitcher />
            </div>

            <div
                className="pointer-events-none absolute inset-0 -z-10"
                aria-hidden="true"
            >
                <div className="absolute -top-48 -left-40 size-[30rem] rounded-full bg-[#16c8ce]/12 blur-3xl" />
                <div className="absolute -right-52 -bottom-40 size-[32rem] rounded-full bg-[#406386]/10 blur-3xl" />
                <div className="absolute inset-x-0 top-0 h-1 bg-gradient-to-l from-[#16c8ce] via-[#406386] to-transparent" />
            </div>

            <main
                id="auth-main"
                tabIndex={-1}
                className="flex min-h-svh items-center justify-center p-5 sm:p-8"
            >
                <div className="w-full max-w-md">
                    <div className="flex flex-col gap-6">
                        <Link
                            href={home()}
                            className="mx-auto flex items-center gap-3 rounded-xl p-1 font-medium focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-[#16c8ce]"
                            aria-label={t('auth.homeLabel')}
                        >
                            <span className="grid size-12 place-items-center rounded-xl border border-[#d5dfe4] bg-white shadow-sm">
                                <img
                                    src="/brand/cloudtech-mark.svg"
                                    alt=""
                                    aria-hidden="true"
                                    className="h-9 w-10 object-contain"
                                />
                            </span>
                            <span className="grid text-start leading-tight">
                                <strong
                                    className="text-base text-[#102d3d]"
                                    data-no-translate
                                >
                                    Project Desk
                                </strong>
                                <span className="text-xs text-[#536675]">
                                    {t('brand.workspace')}
                                </span>
                            </span>
                        </Link>

                        <section
                            className="rounded-3xl border border-white/80 bg-white/95 p-6 shadow-[0_28px_70px_rgba(16,45,61,0.12)] backdrop-blur sm:p-8"
                            aria-labelledby="auth-title"
                        >
                            <header className="mb-7 space-y-2 text-center">
                                <h1
                                    id="auth-title"
                                    className="text-2xl font-black text-[#102d3d]"
                                >
                                    {title}
                                </h1>
                                {description && (
                                    <p className="text-sm leading-6 text-[#536675]">
                                        {description}
                                    </p>
                                )}
                            </header>

                            {children}
                        </section>
                    </div>
                </div>
            </main>
        </div>
    );
}
