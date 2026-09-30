// One trailing render per burst; an old response can never replace a newer preview.
export class PreviewScheduler {
  constructor(render, accept, pending, delay = 800, clock = globalThis) {
    Object.assign(this, {render, accept, pending, delay, clock, sequence: 0, timer: null});
  }
  schedule(immediate = false) {
    const sequence = ++this.sequence;
    this.clock.clearTimeout(this.timer);
    this.pending(true);
    this.timer = this.clock.setTimeout(async () => {
      try {
        const result = await this.render();
        if (sequence === this.sequence) this.accept(result);
      } catch (error) {
        if (sequence === this.sequence) this.accept(null, error);
      } finally {
        if (sequence === this.sequence) this.pending(false);
      }
    }, immediate ? 0 : this.delay);
  }
}

export function requiresTeam(definition, selected, available) {
  return !!definition?.requireGroup && !selected.some(tag => available.includes(tag));
}

export function subjectLabel(prefix, subject) {
  return (prefix ? '[' + prefix + '] ' : '') + subject;
}
