import { useForm } from '@inertiajs/react';
import { Check, Loader2, Plus, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import SocialIcon from '@/components/profile/social-icon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { requestJson } from '@/lib/http';
import { cn } from '@/lib/utils';

export type Basics = {
    display_name: string;
    real_name: string | null;
    real_name_am: string | null;
    slug: string;
    bio: string | null;
    location: string | null;
    timezone: string;
    socials: { url: string; platform?: string | null }[];
    tags: string[];
};

function detectPlatform(url: string): string {
    const host = url
        .replace(/^https?:\/\//i, '')
        .replace(/^(www\.|m\.)/, '')
        .split('/')[0]
        .toLowerCase();

    if (host === 'x.com' || host === 'twitter.com') return 'x';
    if (host === 'github.com') return 'github';
    if (host.endsWith('linkedin.com')) return 'linkedin';
    if (host === 'instagram.com') return 'instagram';
    if (host === 'tiktok.com') return 'tiktok';
    if (host === 'youtube.com' || host === 'youtu.be') return 'youtube';
    if (host.endsWith('substack.com')) return 'substack';

    return 'website';
}

/** Native select dressed like the Input primitive. */
export const selectClass =
    'h-10 w-full rounded-xl border border-input bg-card px-3.5 text-sm shadow-[0_1px_2px_oklch(0_0_0/0.03)] outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-4 focus-visible:ring-ring/25';

/** A titled white card grouping related fields. */
export function FormSection({
    title,
    description,
    children,
    className,
}: {
    title: string;
    description?: string;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <section className={cn('surface p-5 sm:p-6', className)}>
            <div className="mb-5">
                <h2 className="eyebrow">{title}</h2>
                {description && (
                    <p className="mt-1.5 text-sm text-pretty text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            <div className="space-y-5">{children}</div>
        </section>
    );
}

/**
 * Display name, real name (+ Amharic), slug with a live availability check,
 * bio, location, timezone, socials and up to 5 tags (plan §6.3 step 4).
 */
export default function BasicsForm({
    initial,
    action,
    method = 'post',
    submitLabel,
    suggestedTags,
    pendingFields = [],
    reviewNotice = false,
}: {
    initial: Basics;
    action: string;
    method?: 'post' | 'put';
    submitLabel: string;
    suggestedTags: string[];
    pendingFields?: string[];
    reviewNotice?: boolean;
}) {
    const form = useForm<Basics>({
        ...initial,
        socials: initial.socials.length
            ? initial.socials
            : [{ url: '', platform: null }],
    });
    const [slugState, setSlugState] = useState<{
        checking: boolean;
        available: boolean | null;
        message: string | null;
    }>({
        checking: false,
        available: null,
        message: null,
    });
    const [tagDraft, setTagDraft] = useState('');

    const timezones = useMemo(() => {
        try {
            return (
                Intl as unknown as {
                    supportedValuesOf: (k: string) => string[];
                }
            ).supportedValuesOf('timeZone');
        } catch {
            return ['Africa/Addis_Ababa', 'UTC'];
        }
    }, []);

    useEffect(() => {
        const slug = form.data.slug.trim().toLowerCase();

        if (slug === initial.slug || slug.length < 3) {
            setSlugState({ checking: false, available: null, message: null });

            return;
        }

        setSlugState((s) => ({ ...s, checking: true }));

        const timer = window.setTimeout(async () => {
            const { data } = await requestJson<{
                available: boolean;
                message: string | null;
            }>(`/api/slug-check?slug=${encodeURIComponent(slug)}`);
            setSlugState({
                checking: false,
                available: data.available,
                message: data.message,
            });
        }, 300);

        return () => window.clearTimeout(timer);
    }, [form.data.slug, initial.slug]);

    const setSocial = (index: number, url: string) => {
        const socials = [...form.data.socials];
        socials[index] = { url, platform: url ? detectPlatform(url) : null };
        form.setData('socials', socials);
    };

    const addTag = (name: string) => {
        const clean = name.trim();

        if (
            !clean ||
            form.data.tags.length >= 5 ||
            form.data.tags.some((t) => t.toLowerCase() === clean.toLowerCase())
        ) {
            return;
        }

        form.setData('tags', [...form.data.tags, clean]);
        setTagDraft('');
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.submit(method, action, { preserveScroll: true });
    };

    const pendingBadge = (field: string) =>
        pendingFields.includes(field) ? (
            <Badge variant="amber" className="ml-1.5 align-middle">
                In review
            </Badge>
        ) : null;

    return (
        <form onSubmit={submit} className="space-y-4">
            {reviewNotice && (
                <p className="rounded-xl bg-secondary p-3.5 text-sm text-pretty text-muted-foreground">
                    Your profile is live. Bio, socials, tags and location update
                    right away; name and link changes go live after a quick
                    review.
                </p>
            )}

            <FormSection title="Identity">
                <div className="grid gap-5 sm:grid-cols-2">
                    <div className="space-y-2">
                        <Label htmlFor="display_name">
                            Display name {pendingBadge('display_name')}
                        </Label>
                        <Input
                            id="display_name"
                            value={form.data.display_name}
                            onChange={(e) =>
                                form.setData('display_name', e.target.value)
                            }
                            maxLength={60}
                            required
                        />
                        <InputError message={form.errors.display_name} />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="slug">
                            Profile link {pendingBadge('slug')}
                        </Label>
                        <div className="flex h-10 items-center rounded-xl border border-input bg-card shadow-[0_1px_2px_oklch(0_0_0/0.03)] transition-[box-shadow,border-color] focus-within:border-ring focus-within:ring-4 focus-within:ring-ring/25">
                            <span className="pl-3.5 font-mono text-xs text-ink-soft">
                                buildr/
                            </span>
                            <input
                                id="slug"
                                value={form.data.slug}
                                onChange={(e) =>
                                    form.setData(
                                        'slug',
                                        e.target.value
                                            .toLowerCase()
                                            .replace(/[^a-z0-9-]/g, ''),
                                    )
                                }
                                maxLength={30}
                                className="h-full w-full bg-transparent px-1 font-mono text-sm outline-none"
                                required
                            />
                            <span className="flex pr-3">
                                {slugState.checking && (
                                    <Loader2 className="size-4 animate-spin text-muted-foreground" />
                                )}
                                {!slugState.checking &&
                                    slugState.available === true && (
                                        <span className="flex size-5 items-center justify-center rounded-full bg-mint-soft">
                                            <Check className="size-3 text-mint" />
                                        </span>
                                    )}
                                {!slugState.checking &&
                                    slugState.available === false && (
                                        <span className="flex size-5 items-center justify-center rounded-full bg-rose-soft">
                                            <X className="size-3 text-rose" />
                                        </span>
                                    )}
                            </span>
                        </div>
                        <InputError
                            message={
                                form.errors.slug ??
                                (slugState.available === false
                                    ? (slugState.message ?? undefined)
                                    : undefined)
                            }
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="real_name">
                            Real name{' '}
                            <span className="font-normal text-muted-foreground">
                                (optional)
                            </span>{' '}
                            {pendingBadge('real_name')}
                        </Label>
                        <Input
                            id="real_name"
                            value={form.data.real_name ?? ''}
                            onChange={(e) =>
                                form.setData('real_name', e.target.value)
                            }
                            maxLength={80}
                        />
                        <p className="text-xs text-muted-foreground">
                            This will be public on your profile.
                        </p>
                        <InputError message={form.errors.real_name} />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="real_name_am">
                            Name in Amharic{' '}
                            <span className="font-normal text-muted-foreground">
                                (optional)
                            </span>{' '}
                            {pendingBadge('real_name_am')}
                        </Label>
                        <Input
                            id="real_name_am"
                            value={form.data.real_name_am ?? ''}
                            onChange={(e) =>
                                form.setData('real_name_am', e.target.value)
                            }
                            maxLength={80}
                            placeholder="ሙሉ ስም"
                        />
                        <InputError message={form.errors.real_name_am} />
                    </div>
                </div>
            </FormSection>

            <FormSection title="About">
                <div className="space-y-2">
                    <Label htmlFor="bio">Short bio</Label>
                    <Textarea
                        id="bio"
                        value={form.data.bio ?? ''}
                        onChange={(e) => form.setData('bio', e.target.value)}
                        maxLength={600}
                        rows={4}
                        placeholder="What do you build, and who is it for?"
                    />
                    <div className="flex justify-between gap-3 text-xs text-muted-foreground">
                        <InputError message={form.errors.bio} />
                        <span className="tabular ml-auto font-mono">
                            {(form.data.bio ?? '').length}/600
                        </span>
                    </div>
                </div>

                <div className="grid gap-5 sm:grid-cols-2">
                    <div className="space-y-2">
                        <Label htmlFor="location">
                            Location{' '}
                            <span className="font-normal text-muted-foreground">
                                (optional)
                            </span>
                        </Label>
                        <Input
                            id="location"
                            value={form.data.location ?? ''}
                            onChange={(e) =>
                                form.setData('location', e.target.value)
                            }
                            maxLength={80}
                            placeholder="City"
                        />
                        <InputError message={form.errors.location} />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="timezone">Timezone</Label>
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
                </div>
            </FormSection>

            <FormSection
                title="Links"
                description="Paste any profile link; we recognise the platform."
            >
                <div className="space-y-2">
                    {form.data.socials.map((social, i) => (
                        <div key={i} className="flex items-center gap-2">
                            <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-secondary text-muted-foreground">
                                <SocialIcon
                                    platform={social.platform ?? 'website'}
                                    className="size-4"
                                />
                            </span>
                            <Input
                                value={social.url}
                                onChange={(e) => setSocial(i, e.target.value)}
                                placeholder="https://github.com/you"
                                aria-label={`Link ${i + 1}`}
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label="Remove link"
                                onClick={() =>
                                    form.setData(
                                        'socials',
                                        form.data.socials.filter(
                                            (_, j) => j !== i,
                                        ),
                                    )
                                }
                            >
                                <X />
                            </Button>
                        </div>
                    ))}
                    {Object.entries(form.errors)
                        .filter(([key]) => key.startsWith('socials.'))
                        .map(([key, message]) => (
                            <InputError key={key} message={message} />
                        ))}
                    {form.data.socials.length < 10 && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="mt-1"
                            onClick={() =>
                                form.setData('socials', [
                                    ...form.data.socials,
                                    { url: '', platform: null },
                                ])
                            }
                        >
                            <Plus />
                            Add link
                        </Button>
                    )}
                </div>
            </FormSection>

            <FormSection
                title="Tags"
                description="Up to 5. They help people find you on Discover."
            >
                {form.data.tags.length > 0 && (
                    <div className="flex flex-wrap gap-1.5">
                        {form.data.tags.map((tag) => (
                            <span
                                key={tag}
                                className="flex items-center gap-1 rounded-full bg-secondary py-1 pr-1.5 pl-3 text-sm"
                            >
                                {tag}
                                <button
                                    type="button"
                                    aria-label={`Remove ${tag}`}
                                    className="flex size-5 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-card hover:text-foreground"
                                    onClick={() =>
                                        form.setData(
                                            'tags',
                                            form.data.tags.filter(
                                                (t) => t !== tag,
                                            ),
                                        )
                                    }
                                >
                                    <X className="size-3" />
                                </button>
                            </span>
                        ))}
                    </div>
                )}
                {form.data.tags.length < 5 && (
                    <div className="space-y-3">
                        <Input
                            id="tag"
                            aria-label="Add a tag"
                            value={tagDraft}
                            onChange={(e) => setTagDraft(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' || e.key === ',') {
                                    e.preventDefault();
                                    addTag(tagDraft);
                                }
                            }}
                            maxLength={30}
                            placeholder="Type a tag and press Enter"
                        />
                        <div className="flex flex-wrap gap-1.5">
                            {suggestedTags
                                .filter((t) => !form.data.tags.includes(t))
                                .map((tag) => (
                                    <button
                                        key={tag}
                                        type="button"
                                        onClick={() => addTag(tag)}
                                        className="rounded-full border border-dashed border-border px-2.5 py-0.5 text-xs text-muted-foreground transition-colors hover:border-foreground/30 hover:text-foreground"
                                    >
                                        + {tag}
                                    </button>
                                ))}
                        </div>
                    </div>
                )}
                <InputError message={form.errors.tags} />
            </FormSection>

            <div className="pt-2">
                <Button
                    type="submit"
                    size="lg"
                    disabled={form.processing || slugState.available === false}
                >
                    {form.processing && <Loader2 className="animate-spin" />}
                    {submitLabel}
                </Button>
            </div>
        </form>
    );
}
