import BasicsForm from '@/components/creator/basics-form';
import type { Basics } from '@/components/creator/basics-form';
import PageContainer from '@/components/page-container';

type Props = {
    basics: Basics;
    pendingChanges: string[];
    isPublished: boolean;
    suggestedTags: string[];
};

export default function ProfilePage({
    basics,
    pendingChanges,
    isPublished,
    suggestedTags,
}: Props) {
    return (
        <PageContainer
            title="Profile"
            description="Your name, link, bio, socials and tags."
        >
            <BasicsForm
                initial={basics}
                action="/dashboard/profile"
                method="put"
                submitLabel="Save profile"
                suggestedTags={suggestedTags}
                pendingFields={pendingChanges}
                reviewNotice={isPublished}
            />
        </PageContainer>
    );
}

ProfilePage.layout = {
    breadcrumbs: [{ title: 'Profile', href: '/dashboard/profile' }],
};
