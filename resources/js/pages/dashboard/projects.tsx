import { router, useForm } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    Eye,
    EyeOff,
    Pencil,
    Plus,
    Sparkles,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { selectClass } from '@/components/creator/basics-form';
import Glyph from '@/components/figures/glyph';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import { ProjectStatusPill } from '@/components/profile/project-card';
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
import type { ProjectStatus } from '@/types';

type Project = {
    id: number;
    name: string;
    url: string | null;
    description: string | null;
    status: ProjectStatus;
    hidden: boolean;
    source: string;
    mention_count: number;
};

type ChannelProjects = {
    id: number;
    username: string;
    title: string;
    projects: Project[];
};

export default function ProjectsPage({
    channels,
    statuses,
}: {
    channels: ChannelProjects[];
    statuses: ProjectStatus[];
}) {
    const [editing, setEditing] = useState<{
        channelId: number;
        project: Project | null;
    } | null>(null);

    const move = (channel: ChannelProjects, index: number, delta: number) => {
        const ids = channel.projects.map((p) => p.id);
        const target = index + delta;

        if (target < 0 || target >= ids.length) return;

        [ids[index], ids[target]] = [ids[target], ids[index]];
        router.post(
            '/dashboard/projects/reorder',
            { channel_id: channel.id, ids },
            { preserveScroll: true },
        );
    };

    return (
        <PageContainer
            title="Projects"
            description="Projects the AI found in your posts, plus anything you add yourself. Your edits are never overwritten."
        >
            {channels.map((channel) => (
                <section key={channel.id} className="space-y-3">
                    <div className="flex items-center justify-between gap-3">
                        <h2 className="flex items-baseline gap-2">
                            <span className="font-mono text-sm">
                                @{channel.username}
                            </span>
                            <span className="tabular font-mono text-[11px] text-muted-foreground">
                                {channel.projects.length}
                            </span>
                        </h2>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                setEditing({
                                    channelId: channel.id,
                                    project: null,
                                })
                            }
                        >
                            <Plus />
                            Add project
                        </Button>
                    </div>

                    {channel.projects.length === 0 && (
                        <div className="group flex items-center gap-4 rounded-2xl border border-dashed border-border p-5">
                            <Glyph name="project" className="size-10" />
                            <p className="text-sm text-muted-foreground">
                                No projects yet. Add one, or they&apos;ll appear
                                as the AI finds them in your posts.
                            </p>
                        </div>
                    )}

                    {channel.projects.length > 0 && (
                        <ul className="surface divide-y divide-border/70 overflow-hidden">
                            {channel.projects.map((project, i) => (
                                <li
                                    key={project.id}
                                    className={cn(
                                        'flex flex-wrap items-center gap-3 p-4 sm:px-5',
                                        project.hidden && 'bg-background/60',
                                    )}
                                >
                                    <span className="tabular w-5 shrink-0 font-mono text-[11px] text-ink-soft">
                                        {String(i + 1).padStart(2, '0')}
                                    </span>
                                    <div
                                        className={cn(
                                            'min-w-0 flex-1',
                                            project.hidden && 'opacity-60',
                                        )}
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium tracking-tight">
                                                {project.name}
                                            </span>
                                            <ProjectStatusPill
                                                status={project.status}
                                            />
                                            {project.source === 'ai' && (
                                                <Badge
                                                    variant="outline"
                                                    className="text-muted-foreground"
                                                >
                                                    <Sparkles />
                                                    Found by AI
                                                </Badge>
                                            )}
                                            {project.hidden && (
                                                <Badge
                                                    variant="outline"
                                                    className="text-muted-foreground"
                                                >
                                                    Hidden
                                                </Badge>
                                            )}
                                            <span className="tabular font-mono text-[11px] text-muted-foreground">
                                                {project.mention_count} posts
                                            </span>
                                        </div>
                                        {project.description && (
                                            <p className="mt-1 text-sm text-pretty text-muted-foreground">
                                                {project.description}
                                            </p>
                                        )}
                                    </div>
                                    <div className="-mr-2 flex items-center gap-0.5">
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            aria-label="Move up"
                                            onClick={() => move(channel, i, -1)}
                                            disabled={i === 0}
                                        >
                                            <ArrowUp />
                                        </Button>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            aria-label="Move down"
                                            onClick={() => move(channel, i, 1)}
                                            disabled={
                                                i ===
                                                channel.projects.length - 1
                                            }
                                        >
                                            <ArrowDown />
                                        </Button>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            aria-label="Edit"
                                            onClick={() =>
                                                setEditing({
                                                    channelId: channel.id,
                                                    project,
                                                })
                                            }
                                        >
                                            <Pencil />
                                        </Button>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            aria-label={
                                                project.hidden ? 'Show' : 'Hide'
                                            }
                                            onClick={() =>
                                                router.post(
                                                    `/dashboard/projects/${project.id}/toggle`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {project.hidden ? (
                                                <Eye />
                                            ) : (
                                                <EyeOff />
                                            )}
                                        </Button>
                                        {project.source === 'creator' && (
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                aria-label="Delete"
                                                onClick={() =>
                                                    router.delete(
                                                        `/dashboard/projects/${project.id}`,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            ))}

            {editing && (
                <ProjectDialog
                    channelId={editing.channelId}
                    project={editing.project}
                    statuses={statuses}
                    onClose={() => setEditing(null)}
                />
            )}
        </PageContainer>
    );
}

function ProjectDialog({
    channelId,
    project,
    statuses,
    onClose,
}: {
    channelId: number;
    project: Project | null;
    statuses: ProjectStatus[];
    onClose: () => void;
}) {
    const form = useForm({
        channel_id: channelId,
        name: project?.name ?? '',
        url: project?.url ?? '',
        description: project?.description ?? '',
        status: project?.status ?? ('building' as ProjectStatus),
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };

        if (project) {
            form.put(`/dashboard/projects/${project.id}`, options);
        } else {
            form.post('/dashboard/projects', options);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {project ? 'Edit project' : 'Add a project'}
                    </DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="name">Name</Label>
                        <Input
                            id="name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            maxLength={80}
                            required
                        />
                        <InputError message={form.errors.name} />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="url">Link</Label>
                        <Input
                            id="url"
                            value={form.data.url}
                            onChange={(e) =>
                                form.setData('url', e.target.value)
                            }
                            placeholder="https://"
                        />
                        <InputError message={form.errors.url} />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="description">
                            One-line description
                        </Label>
                        <Textarea
                            id="description"
                            rows={2}
                            value={form.data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                            maxLength={300}
                        />
                        <InputError message={form.errors.description} />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="status">Status</Label>
                        <select
                            id="status"
                            value={form.data.status}
                            onChange={(e) =>
                                form.setData(
                                    'status',
                                    e.target.value as ProjectStatus,
                                )
                            }
                            className={cn(selectClass, 'capitalize')}
                        >
                            {statuses.map((s) => (
                                <option key={s} value={s}>
                                    {s}
                                </option>
                            ))}
                        </select>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

ProjectsPage.layout = {
    breadcrumbs: [{ title: 'Projects', href: '/dashboard/projects' }],
};
