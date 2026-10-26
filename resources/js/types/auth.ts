export type User = {
    id: number;
    name: string;
    email: string | null;
    telegram_username: string | null;
    avatar: string | null;
    role: 'creator' | 'admin';
    is_admin: boolean;
    two_factor_enabled: boolean;
    bot_can_message: boolean;
    has_creator: boolean;
    creator_slug: string | null;
};

export type Auth = {
    user: User | null;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
