import { AlfakitComponent, type StandaloneComponentSchema } from '@alfakit/react'
import { Head, Link } from '@inertiajs/react'

interface HelpdeskPortalProps {
    components: StandaloneComponentSchema[]
    meta: {
        view: 'index' | 'create' | 'show' | 'contact' | 'rate'
        title: string
        appName: string
        signedIn: boolean
        poweredBy?: string | null
        links: { portal?: string; newTicket?: string; contact?: string }
        labels: { portal: string; newTicket: string; contact: string; navigation: string }
    }
}

/**
 * The AlfaKit helpdesk's customer pages (portal, ticket, contact form, satisfaction
 * survey), served under /support. Every block is a server-driven AlfaKit component.
 */
export default function HelpdeskPortal({ components, meta }: HelpdeskPortalProps) {
    const links = [
        meta.signedIn && meta.links.portal ? { href: meta.links.portal, label: meta.labels.portal, current: meta.view === 'index' } : null,
        meta.signedIn && meta.links.newTicket ? { href: meta.links.newTicket, label: meta.labels.newTicket, current: meta.view === 'create' } : null,
        meta.links.contact ? { href: meta.links.contact, label: meta.labels.contact, current: meta.view === 'contact' } : null,
    ].filter((link) => link !== null)

    return (
        <>
            <Head title={meta.title}>
                <meta name="robots" content="noindex" />
            </Head>
            <div className="min-h-svh bg-muted/40">
                <header className="border-b bg-background">
                    <div className="mx-auto flex w-full max-w-3xl flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <span className="font-semibold">{meta.appName}</span>
                        {links.length > 0 && (
                            <nav aria-label={meta.labels.navigation} className="flex flex-wrap gap-1 text-sm">
                                {links.map((link) => (
                                    <Link
                                        key={link.href}
                                        href={link.href}
                                        aria-current={link.current ? 'page' : undefined}
                                        className="rounded-md px-3 py-1.5 text-muted-foreground hover:bg-muted hover:text-foreground aria-[current=page]:bg-muted aria-[current=page]:text-foreground"
                                    >
                                        {link.label}
                                    </Link>
                                ))}
                            </nav>
                        )}
                    </div>
                </header>
                <main className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-8">
                    {components.map((component, index) => (
                        <AlfakitComponent
                            key={`${meta.view}-${index}`}
                            component={component}
                            className="rounded-xl border bg-background p-5 shadow-sm sm:p-8"
                        />
                    ))}
                </main>
                {/* "Powered by AlfaKit · Alfa Six", only when HelpdeskPlugin::poweredBy() is on. */}
                {meta.poweredBy ? <div className="pb-6" dangerouslySetInnerHTML={{ __html: meta.poweredBy }} /> : null}
            </div>
        </>
    )
}
