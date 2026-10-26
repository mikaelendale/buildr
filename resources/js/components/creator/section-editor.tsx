import { router } from '@inertiajs/react';
import {
    Eye,
    EyeOff,
    Loader2,
    Pencil,
    RotateCcw,
    Sparkles,
    X,
} from 'lucide-react';
import { useState } from 'react';
import Glyph from '@/components/figures/glyph';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { ChannelChip, ChannelSection, ProjectItem } from '@/types';

export type EditorSection = {
    key: string;
    label: string;
    value: unknown;
    ai_value: unknown;
    overridden: boolean;
    hidden: boolean;
    stale: boolean;
    source: 'creator' | 'admin' | 'ai' | null;
};

export type ChannelEditor = {
    channel: ChannelChip;
    channel_id: number;
    summary: {
        id: number;
        version: number;
        status: string;
        mode: string;
        updated_at: string | null;
    } | null;
    generating: boolean;
    preview: ChannelSection;
    sections: EditorSection[];
    projects: (ProjectItem & { hidden: boolean; source: string })[];
};

type Item = Record<string, unknown>;

const LIST_FIELDS: Record<
    string,
    { field: string; label: string; extra?: { field: string; label: string } }
> = {
    building_now: {
        field: 'line',
        label: 'Line',
        extra: { field: 'project_slug', label: 'Project' },
    },
    eras: {
        field: 'story',
        label: 'Story',
        extra: { field: 'name', label: 'Era name' },
    },
    start_here: { field: 'why', label: 'Why read it' },
    in_their_words: { field: 'quote', label: 'Quote' },
    fun_facts: { field: 'text', label: 'Fact' },
};

function preview(key: string, value: unknown): string {
    if (value === null || value === undefined || value === '') return 'Empty';
    if (typeof value === 'string') return value;
    if (key === 'vibe_tags' && Array.isArray(value)) return value.join(' · ');
    if (key === 'how_they_work' && typeof value === 'object')
        return String((value as Item).text ?? '');
    if (Array.isArray(value)) {
        const field = LIST_FIELDS[key]?.field;

        return value.length === 0
            ? 'Empty'
            : value
                  .map((item) => String((item as Item)[field ?? 'text'] ?? ''))
                  .join(' / ');
    }

    return JSON.stringify(value);
}

/**
 * Per-section edit / hide / regenerate (plan §6.3 step 6, §8.8). The same UI
 * serves creators (baseUrl /dashboard/summary) and admins (/admin/...).
 */
export default function SectionEditor({
    editor,
    baseUrl,
    regenerationsLeft,
    allowRegenerate = true,
}: {
    editor: ChannelEditor;
    baseUrl: string;
    regenerationsLeft?: number;
    allowRegenerate?: boolean;
}) {
    const [editing, setEditing] = useState<EditorSection | null>(null);
    const [busy, setBusy] = useState<string | null>(null);
    const url = (section: string, action = '') =>
        `${baseUrl}/${editor.channel_id}/${section}${action}`;

    const post = (
        section: string,
        action: string,
        data: Record<string, string | boolean> = {},
    ) => {
        setBusy(section + action);
        router.post(url(section, action), data, {
            preserveScroll: true,
            onFinish: () => setBusy(null),
        });
    };

    if (!editor.summary) {
        return (
            <div className="group flex flex-col items-center gap-3 rounded-2xl border border-dashed border-border px-6 py-10 text-center text-sm text-muted-foreground">
                <Glyph name={editor.generating ? 'feed' : 'empty'} />
                {editor.generating ? (
                    <span className="inline-flex items-center gap-2">
                        <Loader2 className="size-4 animate-spin" /> Your profile
                        is still being written…
                    </span>
                ) : (
                    <span className="max-w-sm text-pretty">
                        No AI summary for this channel yet. Basic profiles show
                        your own details, channel info and stats.
                    </span>
                )}
            </div>
        );
    }

    return (
        <div className="space-y-3">
            <div className="surface divide-y divide-border/70 overflow-hidden">
                {editor.sections.map((section) => (
                    <div
                        key={section.key}
                        className={cn(
                            'p-4 transition-colors sm:p-5',
                            section.hidden && 'bg-background/60',
                        )}
                    >
                        <div className="flex flex-wrap items-center gap-2">
                            <h3 className="text-[15px] font-medium tracking-tight">
                                {section.label}
                            </h3>
                            {section.overridden && (
                                <Badge variant="violet">
                                    Edited
                                    {section.source === 'admin'
                                        ? ' by admin'
                                        : ''}
                                </Badge>
                            )}
                            {section.hidden && (
                                <Badge
                                    variant="outline"
                                    className="text-muted-foreground"
                                >
                                    Hidden
                                </Badge>
                            )}
                            <div className="-mr-2 ml-auto flex flex-wrap items-center gap-0.5">
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => setEditing(section)}
                                    disabled={section.hidden}
                                >
                                    <Pencil />
                                    Edit
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        post(section.key, '/hide', {
                                            hidden: !section.hidden,
                                        })
                                    }
                                    disabled={busy !== null}
                                >
                                    {section.hidden ? <Eye /> : <EyeOff />}
                                    {section.hidden ? 'Show' : 'Hide'}
                                </Button>
                                {allowRegenerate && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            post(section.key, '/regenerate')
                                        }
                                        disabled={
                                            busy !== null ||
                                            (regenerationsLeft !== undefined &&
                                                regenerationsLeft <= 0)
                                        }
                                        title={
                                            regenerationsLeft !== undefined
                                                ? `${regenerationsLeft} regenerations left today`
                                                : undefined
                                        }
                                    >
                                        {busy ===
                                        section.key + '/regenerate' ? (
                                            <Loader2 className="animate-spin" />
                                        ) : (
                                            <Sparkles />
                                        )}
                                        Regenerate
                                    </Button>
                                )}
                                {section.overridden && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            post(section.key, '/reset')
                                        }
                                        disabled={busy !== null}
                                    >
                                        <RotateCcw />
                                        Use AI version
                                    </Button>
                                )}
                            </div>
                        </div>

                        <p
                            className={cn(
                                'mt-2 line-clamp-3 max-w-2xl text-sm leading-relaxed text-pretty',
                                section.hidden
                                    ? 'text-muted-foreground line-through decoration-border'
                                    : 'text-foreground/80',
                            )}
                        >
                            {preview(
                                section.key,
                                section.value ?? section.ai_value,
                            )}
                        </p>

                        {section.stale && (
                            <div className="mt-3 flex flex-wrap items-center gap-2 rounded-xl bg-amber-soft p-3 text-sm ring-1 ring-amber/15">
                                <span className="flex-1 text-amber">
                                    The AI updated a section you edited.
                                </span>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => post(section.key, '/keep')}
                                >
                                    Keep my version
                                </Button>
                                <Button
                                    size="sm"
                                    onClick={() => post(section.key, '/reset')}
                                >
                                    Use the new one
                                </Button>
                            </div>
                        )}
                    </div>
                ))}
            </div>

            {regenerationsLeft !== undefined && allowRegenerate && (
                <p className="flex items-center gap-1.5 px-1 text-xs text-muted-foreground">
                    <Sparkles className="size-3.5 text-brand-ink" />
                    <span className="tabular font-mono">
                        {regenerationsLeft}
                    </span>
                    regeneration{regenerationsLeft === 1 ? '' : 's'} left today
                </p>
            )}

            {editing && (
                <EditDialog
                    section={editing}
                    projects={editor.projects}
                    onClose={() => setEditing(null)}
                    onSave={(value) => {
                        router.put(
                            url(editing.key),
                            { value: value as never },
                            {
                                preserveScroll: true,
                                onSuccess: () => setEditing(null),
                            },
                        );
                    }}
                />
            )}
        </div>
    );
}

function EditDialog({
    section,
    projects,
    onClose,
    onSave,
}: {
    section: EditorSection;
    projects: ProjectItem[];
    onClose: () => void;
    onSave: (value: unknown) => void;
}) {
    const initial = section.value ?? section.ai_value;
    const [text, setText] = useState(() => {
        if (typeof initial === 'string') return initial;
        if (section.key === 'vibe_tags' && Array.isArray(initial))
            return initial.join(', ');
        if (
            section.key === 'how_they_work' &&
            initial &&
            typeof initial === 'object'
        )
            return String((initial as Item).text ?? '');

        return '';
    });
    const [items, setItems] = useState<Item[]>(() =>
        Array.isArray(initial) && section.key !== 'vibe_tags'
            ? (initial as Item[]).map((i) => ({ ...i }))
            : [],
    );
    const list = LIST_FIELDS[section.key];

    const save = () => {
        if (list) return onSave(items);
        if (section.key === 'vibe_tags')
            return onSave(
                text
                    .split(',')
                    .map((t) => t.trim())
                    .filter(Boolean),
            );
        if (section.key === 'how_they_work')
            return onSave({ ...(initial as Item | null), text });

        onSave(text);
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Edit: {section.label}</DialogTitle>
                </DialogHeader>

                {list ? (
                    <div className="space-y-3">
                        {items.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                Nothing left in this section.
                            </p>
                        )}
                        {items.map((item, i) => (
                            <div
                                key={i}
                                className="space-y-2 rounded-xl bg-secondary/60 p-3 ring-1 ring-border/60"
                            >
                                <div className="flex items-center justify-between">
                                    <span className="font-mono text-[11px] text-muted-foreground">
                                        #{String(i + 1).padStart(2, '0')}
                                    </span>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        aria-label="Remove"
                                        onClick={() =>
                                            setItems(
                                                items.filter((_, j) => j !== i),
                                            )
                                        }
                                    >
                                        <X />
                                    </Button>
                                </div>
                                {list.extra && list.extra.field === 'name' && (
                                    <div className="space-y-1">
                                        <Label>{list.extra.label}</Label>
                                        <Input
                                            value={String(item.name ?? '')}
                                            maxLength={80}
                                            onChange={(e) =>
                                                setItems(
                                                    items.map((it, j) =>
                                                        j === i
                                                            ? {
                                                                  ...it,
                                                                  name: e.target
                                                                      .value,
                                                              }
                                                            : it,
                                                    ),
                                                )
                                            }
                                        />
                                    </div>
                                )}
                                {list.extra &&
                                    list.extra.field === 'project_slug' && (
                                        <p className="text-sm font-medium">
                                            {projects.find(
                                                (p) =>
                                                    p.slug ===
                                                    item.project_slug,
                                            )?.name ??
                                                String(item.project_slug)}
                                        </p>
                                    )}
                                {section.key === 'in_their_words' ? (
                                    <p className="text-sm text-pretty text-muted-foreground">
                                        “{String(item.quote)}”: quotes stay
                                        word-for-word from your post, so they
                                        can only be removed.
                                    </p>
                                ) : (
                                    <div className="space-y-1">
                                        <Label>{list.label}</Label>
                                        <Textarea
                                            rows={3}
                                            value={String(
                                                item[list.field] ?? '',
                                            )}
                                            onChange={(e) =>
                                                setItems(
                                                    items.map((it, j) =>
                                                        j === i
                                                            ? {
                                                                  ...it,
                                                                  [list.field]:
                                                                      e.target
                                                                          .value,
                                                              }
                                                            : it,
                                                    ),
                                                )
                                            }
                                        />
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                ) : section.key === 'hook' || section.key === 'vibe_tags' ? (
                    <div className="space-y-2">
                        <Input
                            value={text}
                            onChange={(e) => setText(e.target.value)}
                            maxLength={section.key === 'hook' ? 200 : 80}
                        />
                        {section.key === 'vibe_tags' && (
                            <p className="text-xs text-muted-foreground">
                                Up to 3, separated by commas.
                            </p>
                        )}
                    </div>
                ) : (
                    <Textarea
                        rows={6}
                        value={text}
                        onChange={(e) => setText(e.target.value)}
                        maxLength={900}
                    />
                )}

                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save}>Save</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
