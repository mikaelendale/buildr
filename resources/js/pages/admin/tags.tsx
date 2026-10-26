import { router, useForm } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import { EmptyState, FilterSelect, Tray } from '@/components/admin/ui';
import PageContainer from '@/components/page-container';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

type Tag = {
    id: number;
    slug: string;
    name: string;
    description: string | null;
    is_curated: boolean;
    sort: number;
    uses: number;
};

export default function Tags({ tags }: { tags: Tag[] }) {
    const add = useForm({ name: '' });
    const curated = tags.filter((t) => t.is_curated).length;

    return (
        <PageContainer
            title="Tags"
            description="Curated tags appear on the landing page and in filters. Merge AI-made duplicates into a curated one."
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    add.post('/admin/tags', { onSuccess: () => add.reset() });
                }}
                className="flex max-w-md gap-2"
            >
                <Input
                    value={add.data.name}
                    onChange={(e) => add.setData('name', e.target.value)}
                    placeholder="New curated tag"
                />
                <Button
                    type="submit"
                    className="h-10"
                    disabled={add.processing || !add.data.name}
                >
                    Add
                </Button>
            </form>

            <Tray
                label="All tags"
                meta={`${curated} curated · ${tags.length} total`}
            >
                {tags.length === 0 ? (
                    <EmptyState glyph="spark">No tags yet.</EmptyState>
                ) : (
                    <ul className="divide-y divide-border/70">
                        {tags.map((tag) => (
                            <TagRow key={tag.id} tag={tag} tags={tags} />
                        ))}
                    </ul>
                )}
            </Tray>
        </PageContainer>
    );
}

function TagRow({ tag, tags }: { tag: Tag; tags: Tag[] }) {
    const [open, setOpen] = useState(false);
    const [into, setInto] = useState('');
    const form = useForm({
        name: tag.name,
        description: tag.description ?? '',
        is_curated: tag.is_curated,
        sort: tag.sort,
    });

    return (
        <li className="px-4 py-3">
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm font-medium">
                    <span className="text-ink-soft">#</span>
                    {tag.name}
                </span>
                <span className="tabular font-mono text-[11px] text-muted-foreground">
                    /{tag.slug} · used {tag.uses}×
                </span>
                {tag.is_curated && <Badge variant="mint">curated</Badge>}
                <Button
                    size="sm"
                    variant="ghost"
                    className="ml-auto"
                    aria-expanded={open}
                    onClick={() => setOpen(!open)}
                >
                    {open ? 'Close' : 'Edit'}
                    <ChevronDown
                        className={cn(
                            'transition-transform duration-150',
                            open && 'rotate-180',
                        )}
                    />
                </Button>
            </div>
            {open && (
                <div className="mt-3 space-y-3 rounded-xl bg-secondary/60 p-3">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.put(`/admin/tags/${tag.id}`, {
                                preserveScroll: true,
                            });
                        }}
                        className="space-y-2"
                    >
                        <Input
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                        <Textarea
                            rows={2}
                            value={form.data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                            placeholder="Description for the tag page"
                        />
                        <div className="flex flex-wrap items-center gap-4 text-sm">
                            <label className="flex items-center gap-2">
                                <Checkbox
                                    checked={form.data.is_curated}
                                    onCheckedChange={(v) =>
                                        form.setData('is_curated', v === true)
                                    }
                                />
                                Curated
                            </label>
                            <label className="flex items-center gap-2 text-muted-foreground">
                                Order
                                <Input
                                    type="number"
                                    className="h-8 w-20 font-mono"
                                    value={form.data.sort}
                                    onChange={(e) =>
                                        form.setData(
                                            'sort',
                                            Number(e.target.value),
                                        )
                                    }
                                />
                            </label>
                            <Button
                                type="submit"
                                size="sm"
                                className="ml-auto"
                                disabled={form.processing}
                            >
                                Save
                            </Button>
                        </div>
                    </form>
                    <div className="flex flex-wrap items-center gap-2 border-t border-border/70 pt-3 text-sm">
                        <span className="text-muted-foreground">
                            Merge into
                        </span>
                        <FilterSelect
                            value={into}
                            onChange={(e) => setInto(e.target.value)}
                            className="h-8"
                        >
                            <option value="">Pick a tag</option>
                            {tags
                                .filter((t) => t.id !== tag.id)
                                .map((t) => (
                                    <option key={t.id} value={t.id}>
                                        {t.name}
                                    </option>
                                ))}
                        </FilterSelect>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={!into}
                            onClick={() =>
                                router.post(`/admin/tags/${tag.id}/merge`, {
                                    into: Number(into),
                                })
                            }
                        >
                            Merge
                        </Button>
                    </div>
                </div>
            )}
        </li>
    );
}

Tags.layout = { breadcrumbs: [{ title: 'Tags', href: '/admin/tags' }] };
