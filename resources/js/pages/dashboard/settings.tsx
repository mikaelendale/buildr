import { useForm } from '@inertiajs/react';
import { useMemo } from 'react';
import AppearanceTabs from '@/components/appearance-tabs';
import { FormSection, selectClass } from '@/components/creator/basics-form';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    settings: {
        timezone: string;
        notify_summary_digest: boolean;
        bot_can_message: boolean;
        slug: string | null;
    };
    botUsername: string | null;
};

export default function SettingsPage({ settings, botUsername }: Props) {
    const form = useForm({
        timezone: settings.timezone,
        notify_summary_digest: settings.notify_summary_digest,
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
                            {!settings.bot_can_message && botUsername && (
                                <span className="block text-xs text-muted-foreground">
                                    Press Start in{' '}
                                    <a
                                        href={`https://t.me/${botUsername}`}
                                        className="font-mono text-foreground underline decoration-border underline-offset-4"
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        @{botUsername}
                                    </a>{' '}
                                    so we can message you.
                                </span>
                            )}
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
