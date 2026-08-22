import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    CheckCircle2,
    Clock3,
    FolderKanban,
    ShieldCheck,
} from 'lucide-react';
import { LanguageSwitcher, useLocale } from '@/i18n';
import { dashboard, login } from '@/routes';
import type { Auth } from '@/types';

export default function Welcome() {
    const { auth } = usePage<{ auth: Auth }>().props;
    const { direction, isRtl, t } = useLocale();
    const destination = auth.user ? dashboard() : login();
    const ArrowForward = isRtl ? ArrowLeft : ArrowRight;
    const workspaceHighlights = [
        {
            title: t('welcome.featureWorkTitle'),
            description: t('welcome.featureWorkDescription'),
            icon: FolderKanban,
        },
        {
            title: t('welcome.featureTeamTitle'),
            description: t('welcome.featureTeamDescription'),
            icon: Clock3,
        },
        {
            title: t('welcome.featureSecureTitle'),
            description: t('welcome.featureSecureDescription'),
            icon: ShieldCheck,
        },
    ];

    return (
        <>
            <Head title={t('welcome.headTitle')} />

            <div
                className="relative isolate min-h-svh overflow-hidden bg-[#f3f6f7] text-[#102432]"
                dir={direction}
            >
                <a
                    href="#welcome-main"
                    className="absolute start-4 top-4 z-50 -translate-y-24 rounded-lg bg-[#16c8ce] px-4 py-3 font-bold text-[#102d3d] shadow-lg transition-transform focus:translate-y-0 focus:outline-3 focus:outline-offset-2 focus:outline-[#102d3d]"
                >
                    {t('shell.skipToMain')}
                </a>

                <div
                    className="pointer-events-none absolute inset-0 -z-10"
                    aria-hidden="true"
                >
                    <div className="absolute -top-48 -left-32 h-[30rem] w-[30rem] rounded-full bg-[#16c8ce]/12 blur-3xl" />
                    <div className="absolute -right-48 bottom-0 h-[32rem] w-[32rem] rounded-full bg-[#406386]/10 blur-3xl" />
                    <div className="absolute inset-x-0 top-0 h-1 bg-gradient-to-l from-[#16c8ce] via-[#406386] to-transparent" />
                </div>

                <header className="mx-auto flex w-full max-w-7xl items-center justify-between px-5 py-6 sm:px-8 lg:px-12">
                    <Link
                        href="/"
                        className="flex items-center gap-3 rounded-lg focus-visible:outline-3 focus-visible:outline-offset-4 focus-visible:outline-[#16c8ce]"
                        aria-label={t('auth.homeLabel')}
                    >
                        <span className="grid size-11 place-items-center rounded-xl border border-[#d5dfe4] bg-white shadow-sm">
                            <img
                                src="/brand/cloudtech-mark.svg"
                                alt=""
                                aria-hidden="true"
                                className="h-8 w-9 object-contain"
                            />
                        </span>
                        <span className="grid leading-tight">
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

                    <nav
                        className="flex items-center gap-2 sm:gap-3"
                        aria-label={t('welcome.accountActions')}
                    >
                        <LanguageSwitcher />
                        <Link
                            href={destination}
                            className="inline-flex min-h-11 items-center gap-2 rounded-lg border border-[#406386] bg-white px-5 py-2 text-sm font-bold text-[#31516f] shadow-sm transition hover:-translate-y-0.5 hover:bg-[#eef6f7] focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-[#16c8ce] motion-reduce:transform-none"
                        >
                            {auth.user
                                ? t('welcome.openDashboard')
                                : t('welcome.signIn')}
                            <ArrowForward
                                className="size-4"
                                aria-hidden="true"
                            />
                        </Link>
                    </nav>
                </header>

                <main
                    id="welcome-main"
                    tabIndex={-1}
                    className="mx-auto grid w-full max-w-7xl items-center gap-12 px-5 py-10 sm:px-8 sm:py-16 lg:min-h-[calc(100svh-7rem)] lg:grid-cols-[minmax(0,1.08fr)_minmax(22rem,0.92fr)] lg:px-12 lg:py-20"
                >
                    <section aria-labelledby="welcome-title">
                        <p className="mb-5 inline-flex items-center gap-2 rounded-full border border-[#16c8ce]/35 bg-[#dff7f7] px-3 py-1.5 text-xs font-bold text-[#31516f]">
                            <CheckCircle2
                                className="size-4 text-[#137a45]"
                                aria-hidden="true"
                            />
                            {t('welcome.badge')}
                        </p>

                        <h1
                            id="welcome-title"
                            className="max-w-3xl text-4xl leading-[1.25] font-black tracking-tight text-[#102d3d] sm:text-5xl lg:text-6xl"
                        >
                            {t('welcome.heroLineOne')}
                            <span className="block text-[#406386]">
                                {t('welcome.heroLineTwo')}
                            </span>
                        </h1>

                        <p className="mt-6 max-w-2xl text-base leading-8 text-[#536675] sm:text-lg">
                            {t('welcome.description')}
                        </p>

                        <div className="mt-8 flex flex-wrap items-center gap-4">
                            <Link
                                href={destination}
                                className="inline-flex min-h-12 items-center gap-3 rounded-xl bg-[#406386] px-6 py-3 text-sm font-extrabold text-white shadow-[0_14px_32px_rgba(49,81,111,0.22)] transition hover:-translate-y-0.5 hover:bg-[#31516f] focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-[#16c8ce] motion-reduce:transform-none"
                            >
                                {auth.user
                                    ? t('welcome.ctaAuthenticated')
                                    : t('welcome.ctaGuest')}
                                <ArrowForward
                                    className="size-4"
                                    aria-hidden="true"
                                />
                            </Link>
                            <span className="text-sm text-[#536675]">
                                {t('welcome.audience')}
                            </span>
                        </div>
                    </section>

                    <aside
                        className="relative rounded-3xl border border-white/80 bg-white/90 p-5 shadow-[0_28px_70px_rgba(16,45,61,0.12)] backdrop-blur sm:p-7"
                        aria-label={t('welcome.featuresLabel')}
                    >
                        <div
                            className="absolute -end-1 top-6 h-16 w-1 rounded-s-full bg-[#16c8ce]"
                            aria-hidden="true"
                        />
                        <div className="mb-6 border-b border-[#e8eef1] pb-5">
                            <p
                                className="text-xs font-extrabold tracking-wide text-[#406386]"
                                data-no-translate
                            >
                                PROJECT DESK / V1
                            </p>
                            <h2 className="mt-2 text-2xl font-black text-[#102d3d]">
                                {t('welcome.panelTitle')}
                            </h2>
                        </div>

                        <ul className="space-y-3">
                            {workspaceHighlights.map((item) => (
                                <li
                                    key={item.title}
                                    className="grid grid-cols-[3rem_minmax(0,1fr)] items-center gap-4 rounded-2xl border border-[#e8eef1] bg-[#f7f9fa] p-4"
                                >
                                    <span className="grid size-12 place-items-center rounded-xl bg-[#dff7f7] text-[#31516f]">
                                        <item.icon
                                            className="size-5"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <span>
                                        <strong className="block text-sm font-extrabold text-[#102d3d]">
                                            {item.title}
                                        </strong>
                                        <span className="mt-1 block text-xs leading-6 text-[#536675]">
                                            {item.description}
                                        </span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </aside>
                </main>
            </div>
        </>
    );
}
