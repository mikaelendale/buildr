import { Form, Head } from '@inertiajs/react';
import { useRef } from 'react';
import Glyph from '@/components/figures/glyph';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';

// oxfmt-ignore
type Props = {
    passwordRules: string;
    mustSetUp: boolean;
    status?: string;
} &
    ManageTwoFactorProps;

export default function Security(props: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    return (
        <div className="mx-auto w-full max-w-[720px] space-y-6 px-5 pt-8 pb-16 sm:px-8 sm:pt-10">
            <Head title="Security settings" />

            <h1 className="text-[28px] leading-tight font-light tracking-[-0.03em]">
                Security
            </h1>

            {props.mustSetUp && (
                <div className="group flex gap-4 rounded-2xl bg-amber-soft p-4 ring-1 ring-amber/15">
                    <Glyph name="shield" className="size-10 text-amber" />
                    <div className="text-sm">
                        <p className="font-medium text-amber">
                            Two-factor authentication is required
                        </p>
                        <p className="mt-0.5 text-foreground/70">
                            Set up an authenticator app below. Every admin page
                            unlocks once it&apos;s confirmed.
                        </p>
                    </div>
                </div>
            )}

            <section className="surface p-6">
                <Heading
                    variant="small"
                    title="Update password"
                    description="Use a long, random password to keep the admin account safe."
                />

                <Form
                    action="/admin/security/password"
                    method="put"
                    options={{
                        preserveScroll: true,
                    }}
                    resetOnError={[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]}
                    resetOnSuccess
                    onError={(errors) => {
                        if (errors.password) {
                            passwordInput.current?.focus();
                        }

                        if (errors.current_password) {
                            currentPasswordInput.current?.focus();
                        }
                    }}
                    className="mt-6 space-y-5"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="current_password">
                                    Current password
                                </Label>

                                <PasswordInput
                                    id="current_password"
                                    ref={currentPasswordInput}
                                    name="current_password"
                                    className="block w-full"
                                    autoComplete="current-password"
                                    placeholder="Current password"
                                />

                                <InputError message={errors.current_password} />
                            </div>

                            <div className="grid gap-5 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="password">
                                        New password
                                    </Label>

                                    <PasswordInput
                                        id="password"
                                        ref={passwordInput}
                                        name="password"
                                        className="block w-full"
                                        autoComplete="new-password"
                                        placeholder="New password"
                                        passwordrules={props.passwordRules}
                                    />

                                    <InputError message={errors.password} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password_confirmation">
                                        Confirm password
                                    </Label>

                                    <PasswordInput
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        className="block w-full"
                                        autoComplete="new-password"
                                        placeholder="Confirm password"
                                        passwordrules={props.passwordRules}
                                    />

                                    <InputError
                                        message={errors.password_confirmation}
                                    />
                                </div>
                            </div>

                            <Button
                                disabled={processing}
                                data-test="update-password-button"
                            >
                                Save password
                            </Button>
                        </>
                    )}
                </Form>
            </section>

            {props.canManageTwoFactor && (
                <section className="surface p-6">
                    <ManageTwoFactor
                        canManageTwoFactor={props.canManageTwoFactor}
                        requiresConfirmation={props.requiresConfirmation}
                        twoFactorEnabled={props.twoFactorEnabled}
                    />
                </section>
            )}
        </div>
    );
}

Security.layout = {
    breadcrumbs: [
        {
            title: 'Security settings',
            href: '/admin/security',
        },
    ],
};
