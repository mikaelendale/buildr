import { useForm } from '@inertiajs/react';
import { Card } from '@/components/admin/ui';
import Glyph from '@/components/figures/glyph';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';

export default function AddChannels() {
    const form = useForm({ handles: '' });
    const count = form.data.handles.split(/[\s,]+/).filter(Boolean).length;

    return (
        <PageContainer
            title="Add channels"
            description="Paste one or many handles or t.me links. Each one is checked, scraped and processed, then lands in the review queue as an unclaimed page."
        >
            <Card>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/admin/channels');
                    }}
                    className="space-y-4"
                >
                    <div className="group flex items-center gap-3">
                        <Glyph name="feed" className="size-10" />
                        <p className="text-sm text-muted-foreground">
                            One per line, or separated by commas.
                        </p>
                    </div>
                    <Textarea
                        rows={12}
                        value={form.data.handles}
                        onChange={(e) =>
                            form.setData('handles', e.target.value)
                        }
                        className="font-mono text-sm"
                        placeholder={
                            '@channel_one\nhttps://t.me/channel_two\nchannel_three'
                        }
                    />
                    <InputError message={form.errors.handles} />
                    <div className="flex items-center justify-between gap-3">
                        <span className="tabular font-mono text-xs text-muted-foreground">
                            {count} handle{count === 1 ? '' : 's'} detected
                        </span>
                        <Button
                            type="submit"
                            disabled={form.processing || count === 0}
                        >
                            Add {count || ''} channel{count === 1 ? '' : 's'}
                        </Button>
                    </div>
                </form>
            </Card>
        </PageContainer>
    );
}

AddChannels.layout = {
    breadcrumbs: [
        { title: 'Channels', href: '/admin/channels' },
        { title: 'Add', href: '/admin/channels/new' },
    ],
};
