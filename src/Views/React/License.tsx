import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, useForm } from '@inertiajs/react';
import axios from 'axios';
import { FormEventHandler, useState } from 'react';

import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'License settings',
        href: '/settings/license',
    },
];

type LicenseForm = {
    license_key: string;
    server_url: string;
    product_id: string;
    offline_mode: boolean;
};

type LicenseSettingsProps = {
    licenseSettings: LicenseForm;
};

export default function License({ licenseSettings }: LicenseSettingsProps) {
    const [testResult, setTestResult] = useState<{ success: boolean; message: string } | null>(null);
    const [testing, setTesting] = useState(false);

    const { data, setData, put, errors, processing, recentlySuccessful } = useForm<LicenseForm>({
        license_key: licenseSettings.license_key || '',
        server_url: licenseSettings.server_url || 'http://localhost:8001',
        product_id: licenseSettings.product_id || '',
        offline_mode: licenseSettings.offline_mode ?? true,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        put(route('settings.license.update'), {
            preserveScroll: true,
            onSuccess: () => {
                setTestResult(null);
            },
        });
    };

    const testConnection = async () => {
        setTesting(true);
        setTestResult(null);

        try {
            const response = await axios.post(route('settings.license.test'), {
                license_key: data.license_key,
                server_url: data.server_url,
                product_id: data.product_id,
            });

            setTestResult({
                success: true,
                message: response.data.message || 'Connection successful',
            });
        } catch (error: any) {
            setTestResult({
                success: false,
                message: error.response?.data?.message || 'Connection failed',
            });
        } finally {
            setTesting(false);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="License settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="License configuration"
                        description="Manage your application license key and server connection settings"
                    />

                    <form onSubmit={submit} className="space-y-6">
                        {/* License Key */}
                        <div>
                            <Label htmlFor="license_key">License Key</Label>
                            <Input
                                id="license_key"
                                type="password"
                                className="mt-1"
                                value={data.license_key}
                                onChange={(e) => setData('license_key', e.target.value)}
                                required
                                autoComplete="off"
                                placeholder="XXXX-XXXX-XXXX-XXXX"
                            />
                            <InputError message={errors.license_key} className="mt-2" />
                            <p className="mt-1 text-sm text-neutral-500">
                                Your unique license key provided by the license server administrator
                            </p>
                        </div>

                        {/* Server URL */}
                        <div>
                            <Label htmlFor="server_url">License Server URL</Label>
                            <Input
                                id="server_url"
                                type="url"
                                className="mt-1"
                                value={data.server_url}
                                onChange={(e) => setData('server_url', e.target.value)}
                                required
                                placeholder="https://license.example.com"
                            />
                            <InputError message={errors.server_url} className="mt-2" />
                            <p className="mt-1 text-sm text-neutral-500">
                                The URL of your license validation server
                            </p>
                        </div>

                        {/* Product ID */}
                        <div>
                            <Label htmlFor="product_id">Product ID</Label>
                            <Input
                                id="product_id"
                                type="text"
                                className="mt-1"
                                value={data.product_id}
                                onChange={(e) => setData('product_id', e.target.value)}
                                required
                                placeholder="01234567-89ab-cdef-0123-456789abcdef"
                            />
                            <InputError message={errors.product_id} className="mt-2" />
                            <p className="mt-1 text-sm text-neutral-500">UUID format product identifier</p>
                        </div>

                        {/* Offline Mode */}
                        <div className="flex items-center justify-between rounded-lg border p-4">
                            <div className="space-y-0.5">
                                <Label htmlFor="offline_mode">Offline validation mode</Label>
                                <p className="text-sm text-neutral-500">
                                    Enable offline license validation with JWT tokens
                                </p>
                            </div>
                            <Switch
                                id="offline_mode"
                                checked={data.offline_mode}
                                onCheckedChange={(checked) => setData('offline_mode', checked)}
                            />
                        </div>

                        {/* Test Connection Button */}
                        <div>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={testConnection}
                                disabled={testing || !data.license_key || !data.server_url || !data.product_id}
                            >
                                {testing ? 'Testing...' : 'Test connection'}
                            </Button>
                        </div>

                        {/* Test Result */}
                        {testResult && (
                            <Alert variant={testResult.success ? 'default' : 'destructive'}>
                                <AlertDescription>{testResult.message}</AlertDescription>
                            </Alert>
                        )}

                        {/* Actions */}
                        <div className="flex items-center gap-4">
                            <Button type="submit" disabled={processing}>
                                Save changes
                            </Button>

                            <Transition
                                show={recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-sm text-neutral-600">Saved.</p>
                            </Transition>
                        </div>
                    </form>

                    {/* Security Notice */}
                    <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950">
                        <h3 className="text-sm font-medium text-amber-900 dark:text-amber-300">Security notice</h3>
                        <p className="mt-1 text-sm text-amber-700 dark:text-amber-400">
                            License keys are stored encrypted in the database. Only administrators can view and modify
                            license settings.
                        </p>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
