import { Form, usePage } from '@inertiajs/react';
import { Flag } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';

const REASONS: Record<string, string> = {
    impersonation: 'Impersonation',
    wrong_info: 'Wrong information',
    privacy: 'Privacy concern',
    offensive: 'Offensive content',
    spam: 'Spam',
    remove_me: 'This is me, remove it',
    other: 'Something else',
};

/**
 * Report modal on every profile/channel page (plan §9.9).
 */
export default function ReportDialog({
    targetType,
    targetId,
}: {
    targetType: 'creator' | 'channel';
    targetId: number;
}) {
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState('wrong_info');
    const { auth } = usePage().props;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    className="text-muted-foreground"
                >
                    <Flag />
                    Report
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Report this page</DialogTitle>
                    <DialogDescription>
                        Tell us what&apos;s wrong. We review every report within
                        72 hours.
                    </DialogDescription>
                </DialogHeader>

                <Form
                    action="/reports"
                    method="post"
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    transform={(data) => ({
                        ...data,
                        target_type: targetType,
                        target_id: targetId,
                        reason,
                    })}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="space-y-2">
                                <Label>Reason</Label>
                                <Select
                                    value={reason}
                                    onValueChange={setReason}
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(REASONS).map(
                                            ([value, label]) => (
                                                <SelectItem
                                                    key={value}
                                                    value={value}
                                                >
                                                    {label}
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.reason} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="details">Details</Label>
                                <Textarea
                                    id="details"
                                    name="details"
                                    rows={4}
                                    maxLength={2000}
                                    placeholder="What should we know?"
                                />
                                <InputError message={errors.details} />
                            </div>

                            {!auth.user && (
                                <div className="space-y-2">
                                    <Label htmlFor="contact">
                                        How can we reach you? (optional)
                                    </Label>
                                    <Input
                                        id="contact"
                                        name="contact"
                                        maxLength={200}
                                        placeholder="@telegram or email"
                                    />
                                    <InputError message={errors.contact} />
                                </div>
                            )}

                            <DialogFooter>
                                <Button type="submit" disabled={processing}>
                                    Send report
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
