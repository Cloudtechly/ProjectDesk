import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

const defaultMessage = 'لديك تغييرات غير محفوظة. هل تريد تجاهلها والمتابعة؟';

type HistoryGuard = {
    isDirty: () => boolean;
    message: string;
};

const historyGuards = new Set<HistoryGuard>();
let historyGuardInstalled = false;
let restoringHistory = false;

export function installUnsavedHistoryGuard() {
    if (historyGuardInstalled || typeof window === 'undefined') {
        return;
    }

    historyGuardInstalled = true;
    window.addEventListener(
        'popstate',
        (event) => {
            if (restoringHistory) {
                restoringHistory = false;
                event.stopImmediatePropagation();

                return;
            }

            const activeGuard = [...historyGuards]
                .reverse()
                .find((guard) => guard.isDirty());

            if (!activeGuard || window.confirm(activeGuard.message)) {
                return;
            }

            event.stopImmediatePropagation();
            restoringHistory = true;
            window.history.forward();
        },
        true,
    );
}

export function useUnsavedChanges(
    isDirty: boolean,
    message: string = defaultMessage,
) {
    const dirtyRef = useRef(isDirty);
    const allowNextRef = useRef(false);

    useEffect(() => {
        dirtyRef.current = isDirty;
    }, [isDirty]);

    useEffect(() => {
        const removeBeforeListener = router.on('before', (event) => {
            if (!dirtyRef.current) {
                return;
            }

            if (allowNextRef.current) {
                allowNextRef.current = false;

                return;
            }

            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });

        return removeBeforeListener;
    }, [message]);

    useEffect(() => {
        if (!isDirty) {
            return;
        }

        const warnBeforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };

        window.addEventListener('beforeunload', warnBeforeUnload);

        return () =>
            window.removeEventListener('beforeunload', warnBeforeUnload);
    }, [isDirty]);

    useEffect(() => {
        const guard: HistoryGuard = {
            isDirty: () => dirtyRef.current,
            message,
        };
        historyGuards.add(guard);

        return () => {
            historyGuards.delete(guard);
        };
    }, [message]);

    const allowNextNavigation = useCallback(() => {
        allowNextRef.current = true;
    }, []);

    const setDirtyImmediately = useCallback((dirty: boolean) => {
        dirtyRef.current = dirty;
    }, []);

    const confirmDiscard = useCallback(
        () => !dirtyRef.current || window.confirm(message),
        [message],
    );

    const confirmAndAllowNavigation = useCallback(() => {
        if (!confirmDiscard()) {
            return false;
        }

        if (dirtyRef.current) {
            allowNextRef.current = true;
        }

        return true;
    }, [confirmDiscard]);

    return {
        allowNextNavigation,
        confirmAndAllowNavigation,
        confirmDiscard,
        setDirtyImmediately,
    };
}

export function useUnsavedDialog(
    initialOpen = false,
    message: string = defaultMessage,
) {
    const [open, setOpen] = useState(initialOpen);
    const [isDirty, setIsDirty] = useState(false);
    const { allowNextNavigation, confirmDiscard, setDirtyImmediately } =
        useUnsavedChanges(open && isDirty, message);

    const onOpenChange = useCallback(
        (nextOpen: boolean) => {
            if (nextOpen) {
                setDirtyImmediately(false);
                setIsDirty(false);
                setOpen(true);

                return true;
            }

            if (!confirmDiscard()) {
                return false;
            }

            setDirtyImmediately(false);
            setIsDirty(false);
            setOpen(false);

            return true;
        },
        [confirmDiscard, setDirtyImmediately],
    );

    const markDirty = useCallback(() => {
        setDirtyImmediately(true);
        setIsDirty(true);
    }, [setDirtyImmediately]);
    const closeAfterSuccess = useCallback(() => {
        setDirtyImmediately(false);
        setIsDirty(false);
        setOpen(false);
    }, [setDirtyImmediately]);

    return {
        allowNextNavigation,
        closeAfterSuccess,
        isDirty,
        markDirty,
        onOpenChange,
        open,
    };
}
