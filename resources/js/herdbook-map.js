/*
 * The map (App\Filament\Pages\Map): draws the lines between the nodes in an
 * SVG layer, lights up what a node hangs together with, and shows what a
 * server takes down with it. Plain ES module, no build step: Filament loads
 * it as an Alpine component (x-load) and AdminPanelProvider versions it.
 *
 * The nodes are Blade and carry their edges in data-edges; Livewire may
 * refresh them (a poll), after which the lines are drawn again.
 *
 * The texts come translated from the page (map.blade.php): :name
 * placeholders, and "one|more" forms as in Laravel's trans_choice().
 */
export default function herdbookMap(text = {}) {
    const svgNamespace = 'http://www.w3.org/2000/svg'

    const replace = (line, values) => String(line ?? '').replace(/:(\w+)/g, (match, name) => (name in values ? String(values[name]) : match))

    // One and more only: a language with more plural forms needs this extended.
    const choice = (line, count) => {
        const forms = String(line ?? '').split('|')

        return replace(count === 1 ? forms[0] : forms[forms.length - 1], { count })
    }

    return {
        impact: null,
        loading: false,
        selected: null,
        lit: null,
        hovered: null,
        frame: null,

        init() {
            this.schedule()

            this.resizeObserver = new ResizeObserver(() => this.schedule())
            this.resizeObserver.observe(this.$refs.canvas)

            // A refresh changes text or nodes, never the classes set here
            // (wire:ignore.self): only those changes call for a new drawing.
            this.mutationObserver = new MutationObserver(() => this.schedule())
            this.mutationObserver.observe(this.$refs.nodes, {
                childList: true,
                subtree: true,
                characterData: true,
                attributeFilter: ['data-edges'],
            })

            document.fonts?.ready.then(() => this.schedule())
        },

        destroy() {
            this.resizeObserver?.disconnect()
            this.mutationObserver?.disconnect()
            cancelAnimationFrame(this.frame)
        },

        edges() {
            return this.graph().edges
        },

        /*
         * The edges, and per node where they lead in either direction:
         * parsed once per change of data-edges, not on every hover.
         */
        graph() {
            const source = this.$refs.nodes.dataset.edges || '[]'

            if (this.parsed?.source === source) {
                return this.parsed
            }

            let edges = []

            try {
                edges = JSON.parse(source)
            } catch {
                edges = []
            }

            const forward = new Map()
            const backward = new Map()

            for (const edge of edges) {
                forward.set(edge.from, [...(forward.get(edge.from) ?? []), edge.to])
                backward.set(edge.to, [...(backward.get(edge.to) ?? []), edge.from])
            }

            this.parsed = { source, edges, forward, backward }

            return this.parsed
        },

        schedule() {
            cancelAnimationFrame(this.frame)
            this.frame = requestAnimationFrame(() => this.draw())
        },

        draw() {
            const canvas = this.$refs.canvas.getBoundingClientRect()
            const svg = this.$refs.lines
            const drawing = document.createDocumentFragment()

            const nodes = new Map([...this.$refs.nodes.querySelectorAll('[data-node]')].map((node) => [node.dataset.node, node]))

            for (const edge of this.edges()) {
                const from = nodes.get(edge.from)
                const to = nodes.get(edge.to)

                if (!from || !to) {
                    continue
                }

                const a = from.getBoundingClientRect()
                const b = to.getBoundingClientRect()
                const path = document.createElementNS(svgNamespace, 'path')
                let label = null

                if (edge.kind === 'depends') {
                    // Both servers sit in the same column: an arc on its left.
                    const x = Math.min(a.left, b.left) - canvas.left
                    const y1 = a.top + a.height / 2 - canvas.top
                    const y2 = b.top + b.height / 2 - canvas.top
                    const bulge = Math.min(44, 14 + Math.abs(y2 - y1) / 5)

                    path.setAttribute('d', `M ${x} ${y1} C ${x - bulge} ${y1}, ${x - bulge} ${y2}, ${x} ${y2}`)
                    label = { x: x - bulge * 0.75 - 4, y: (y1 + y2) / 2 }
                } else {
                    const x1 = a.right - canvas.left
                    const y1 = a.top + a.height / 2 - canvas.top
                    const x2 = b.left - canvas.left
                    const y2 = b.top + b.height / 2 - canvas.top
                    const dx = (x2 - x1) / 2

                    path.setAttribute('d', `M ${x1} ${y1} C ${x1 + dx} ${y1}, ${x2 - dx} ${y2}, ${x2} ${y2}`)
                }

                path.setAttribute('class', `hb-edge hb-edge-${edge.kind}`)
                path.dataset.from = edge.from
                path.dataset.to = edge.to
                drawing.append(path)

                if (label && edge.label) {
                    const text = document.createElementNS(svgNamespace, 'text')

                    text.setAttribute('x', label.x)
                    text.setAttribute('y', label.y)
                    text.setAttribute('class', 'hb-edge-label')
                    text.setAttribute('text-anchor', 'end')
                    text.setAttribute('dominant-baseline', 'middle')
                    text.dataset.from = edge.from
                    text.dataset.to = edge.to
                    text.textContent = edge.label

                    // The whole kind on hovering, when the label is cut short.
                    const title = document.createElementNS(svgNamespace, 'title')
                    title.textContent = edge.title ?? edge.label
                    text.append(title)
                    drawing.append(text)
                }
            }

            svg.replaceChildren(drawing)
            this.paint()
        },

        /*
         * The node plus everything reachable from it in either direction:
         * what it needs (towards the providers) and what needs it (towards
         * the domains). Not sideways: an environment lights up its servers,
         * not the other environments on those servers.
         */
        connected(id) {
            const graph = this.graph()
            const lit = new Set([id])

            for (const next of [graph.forward, graph.backward]) {
                const queue = [id]
                const seen = new Set(queue)

                while (queue.length > 0) {
                    for (const there of next.get(queue.shift()) ?? []) {
                        if (!seen.has(there)) {
                            seen.add(there)
                            lit.add(there)
                            queue.push(there)
                        }
                    }
                }
            }

            return lit
        },

        enter(event) {
            const node = event.target.closest('[data-node]')

            if (!node || this.selected || this.hovered === node.dataset.node) {
                return
            }

            this.hovered = node.dataset.node
            this.lit = this.connected(this.hovered)
            this.paint()
        },

        leave(event) {
            // Moving within the map from one node to another: enter() takes it.
            if (this.selected || event.relatedTarget?.closest?.('[data-node]')) {
                return
            }

            this.hovered = null
            this.lit = null
            this.paint()
        },

        async click(event) {
            const node = event.target.closest('[data-node^="server-"]')

            if (!node) {
                return
            }

            const id = node.dataset.node

            if (this.selected === id) {
                this.clear()

                return
            }

            // The previous answer stays in view until this one is in: no jumping.
            this.selected = id
            this.loading = true
            this.lit = new Set([id])
            this.paint()

            let impact

            try {
                impact = await this.$wire.impact(Number(id.slice('server-'.length)))
            } catch {
                impact = null
            }

            // Another server was clicked (or the map closed) in the meantime.
            if (this.selected !== id) {
                return
            }

            this.loading = false

            if (!impact) {
                this.impact = { error: true, items: [], servers: 0 }
                this.paint()

                return
            }

            const lit = new Set([id, ...impact.nodes])

            // The hostnames of an environment that goes down go with it.
            for (const edge of this.edges()) {
                if (edge.kind === 'points' && lit.has(edge.from)) {
                    lit.add(edge.to)
                }
            }

            this.impact = impact
            this.lit = lit
            this.paint()
        },

        /* "3 environments and 1 other server": what the open server takes down. */
        affected() {
            const environments = choice(text.environments, this.impact?.items.length ?? 0)

            return this.impact?.servers > 0
                ? replace(text.environmentsAndServers, { environments, servers: choice(text.servers, this.impact.servers) })
                : environments
        },

        /* How an environment in the panel depends on the server. */
        path(item) {
            return item.path ? replace(text.via, { path: item.path }) : text.runsOnIt
        },

        clear() {
            this.selected = null
            this.loading = false
            this.impact = null
            this.hovered = null
            this.lit = null
            this.paint()
        },

        paint() {
            const lit = this.lit

            this.$root.classList.toggle('hb-map-focus', lit !== null)
            this.$root.classList.toggle('hb-map-impact-mode', this.selected !== null && this.impact !== null)

            for (const node of this.$refs.nodes.querySelectorAll('[data-node]')) {
                node.classList.toggle('hb-lit', lit !== null && lit.has(node.dataset.node))
                node.classList.toggle('hb-selected', node.dataset.node === this.selected)

                if (node.tagName === 'BUTTON') {
                    node.setAttribute('aria-pressed', String(node.dataset.node === this.selected))
                }
            }

            for (const line of this.$refs.lines.querySelectorAll('[data-from]')) {
                line.classList.toggle('hb-lit', lit !== null && lit.has(line.dataset.from) && lit.has(line.dataset.to))
            }
        },
    }
}
