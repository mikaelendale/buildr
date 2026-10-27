import { router, useForm } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { useMemo, useState } from 'react';
import AppearanceTabs from '@/components/appearance-tabs';
import { FormSection, selectClass } from '@/components/creator/basics-form';
import {
    ConnectTelegramButton,
    TelegramConnected,
    useTelegramLink,
} from '@/components/creator/telegram-connect';
import type { TelegramLink } from '@/components/creator/telegram-connect';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    settings: {
        timezone: string;
        notify_summary_digest: boolean;
        notify_product_updates: boolean;
        bot_can_message: boolean;
        slug: string | null;
    };
    telegram: TelegramLink & { connected_at: string | null };
    botUsername: string | null;
};

/**
 * The bot is the only way we reach people (no email): connect it, see
 * whether updates are paused, get a password link, or disconnect.
 */
function TelegramSection({
    initial,
    canMessage,
    botUsername,
}: {
    initial: TelegramLink;
    canMessage: boolean;
    botUsername: string | null;
}) {
    const link = useTelegramLink(initial);
    const [sending, setSending] = useState(false);

    return (
        <FormSection
            title="Telegram"
            description="There’s no email: our bot is how you reset your password and hear about your page."
        >
            {link.connected ? (
                <>
                    <div className="flex flex-wrap items-center gap-3">
                        <TelegramConnected username={link.username} />
                        {!canMessage && botUsername && (
                            <span className="text-xs text-muted-foreground">
                                Updates are paused. Send /start to{' '}
                                <a
                                    href={`https://t.me/${botUsername}`}
                                    target="_blank"
                                    rel="noopener"
                                    className="font-mono text-foreground underline decoration-border underline-offset-4"
                                >
                                    @{botUsername}
                                </a>{' '}
                                to turn them back on.
                            </span>
                        )}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={sending}
                            onClick={() =>
                                router.post(
                                    '/dashboard/settings/password-link',
                                    {},
                                    {
                                        preserveScroll: true,
                                        onStart: () => setSending(true),
                                        onFinish: () => setSending(false),
                                    },
                                )
                            }
                        >
                            {sending ? <Spinner /> : <KeyRound />}
                            Change password
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            className="text-muted-foreground"
                            onClick={() =>
                                router.delete('/telegram/connect', {
                                    preserveScroll: true,
                                })
                            }
                        >
                            Disconnect
                        </Button>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Change password sends a link to the bot. Forgot it while
                        logged out? Send /reset to the bot.
                    </p>
                </>
            ) : (
                <div className="flex flex-wrap items-center gap-3">
                    <ConnectTelegramButton
                        waiting={link.waiting}
                        onStart={link.start}
                    />
                    <span className="text-xs text-pretty text-muted-foreground">
                        {link.waiting
                            ? `Press Start in @${botUsername ?? 'the bot'}, then come back here.`
                            : 'Without it you can’t reset a forgotten password.'}
                    </span>
                </div>
            )}
        </FormSection>
    );
}

export default function SettingsPage({
    settings,
    telegram,
    botUsername,
}: Props) {
    const form = useForm({
        timezone: settings.timezone,
        notify_summary_digest: settings.notify_summary_digest,
        notify_product_updates: settings.notify_product_updates,
    });
    const deletion = useForm({ confirm: '', keep_channels: false });

    const timezones = useMemo(() => {
        try {
            return (
                Intl as unknown as {
                    supportedValuesOf: (k: string) => string[];
                }
            ).supportedValuesOf('timeZone');
        } catch {
            return [settings.timezone];
        }
    }, [settings.timezone]);

    return (
        <PageContainer title="Settings">
            <TelegramSection
                key={telegram.connected ? 'on' : 'off'}
                initial={telegram}
                canMessage={settings.bot_can_message}
                botUsername={botUsername}
            />

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.put('/dashboard/settings', { preserveScroll: true });
                }}
            >
                <FormSection title="Profile & notifications">
                    <div className="space-y-2">
                        <Label htmlFor="timezone">Timezone</Label>
                        <p className="text-xs text-muted-foreground">
                            Used for your posting stats (night owl, early bird,
                            streaks).
                        </p>
                        <select
                            id="timezone"
                            value={form.data.timezone}
                            onChange={(e) =>
                                form.setData('timezone', e.target.value)
                            }
                            className={selectClass}
                        >
                            {timezones.map((tz) => (
                                <option key={tz} value={tz}>
                                    {tz}
                                </option>
                            ))}
                        </select>
                        <InputError message={form.errors.timezone} />
                    </div>

                    <label className="flex items-start gap-3">
                        <Checkbox
                            checked={form.data.notify_summary_digest}
                            onCheckedChange={(v) =>
                                form.setData(
                                    'notify_summary_digest',
                                    v === true,
                                )
                            }
                        />
                        <span className="text-sm">
                            Weekly Telegram message when the AI updates my
                            profile
                            {!telegram.connected && (
                                <span className="block text-xs text-muted-foreground">
                                    Connect Telegram above to get it.
                                </span>
                            )}
                        </span>
                    </label>

                    <label className="flex items-start gap-3">
                        <Checkbox
                            checked={form.data.notify_product_updates}
                            onCheckedChange={(v) =>
                                form.setData(
                                    'notify_product_updates',
                                    v === true,
                                )
                            }
                        />
                        <span className="text-sm">
                            Telegram message when Buildr ships something new
                            <span className="block text-xs text-muted-foreground">
                                A few times a month at most.
                            </span>
                        </span>
                    </label>

                    <Button type="submit" disabled={form.processing}>
                        Save
                    </Button>
                </FormSection>
            </form>

            <FormSection
                title="Appearance"
                description="Light, dark, or follow your device."
            >
                <AppearanceTabs />
            </FormSection>

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    deletion.delete('/dashboard/settings/account');
                }}
                className="space-y-5 rounded-2xl bg-card p-5 shadow-soft ring-1 ring-rose/20 sm:p-6"
            >
                <div>
                    <h2 className="font-mono text-[11px] font-medium tracking-[0.08em] text-rose uppercase">
                        Delete account
                    </h2>
                    <p className="mt-1.5 text-sm text-pretty text-muted-foreground">
                        Your profile, edits and socials are deleted. By default
                        your channels are removed and blocklisted too.
                    </p>
                </div>
                <label className="flex items-start gap-3 rounded-xl bg-secondary/70 p-3 text-sm">
                    <Checkbox
                        checked={deletion.data.keep_channels}
                        onCheckedChange={(v) =>
                            deletion.setData('keep_channels', v === true)
                        }
                    />
                    Keep my channels listed as unclaimed pages instead (no name,
                    no socials).
                </label>
                <div className="space-y-2">
                    <Label htmlFor="confirm">
                        Type{' '}
                        <code className="rounded-md bg-secondary px-1.5 py-0.5 font-mono text-xs">
                            {settings.slug ?? 'delete'}
                        </code>{' '}
                        to confirm
                    </Label>
                    <Input
                        id="confirm"
                        value={deletion.data.confirm}
                        onChange={(e) =>
                            deletion.setData('confirm', e.target.value)
                        }
                    />
                    <InputError message={deletion.errors.confirm} />
                </div>
                <Button
                    type="submit"
                    variant="destructive"
                    disabled={
                        deletion.processing ||
                        deletion.data.confirm !== (settings.slug ?? 'delete')
                    }
                >
                    Delete my account
                </Button>
            </form>
        </PageContainer>
    );
}

SettingsPage.layout = {
    breadcrumbs: [{ title: 'Settings', href: '/dashboard/settings' }],
};
