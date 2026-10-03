// The smallest pack studio: plain JS, no build step, no framework — the
// narrow tier of the mount contract (chat-ui/src/mount/contract.ts).
export const apiVersion = 1;

export function mount(el, ctx) {
  const root = document.createElement("section");
  root.className = "pack-hello";
  const title = document.createElement("h2");
  title.textContent = `Hello from ${ctx.studio.name}`;
  const lede = document.createElement("p");
  lede.className = "pack-hello__lede";
  lede.textContent = "This rail is a pack studio, mounted through the console's mount boundary.";
  const ask = document.createElement("button");
  ask.type = "button";
  ask.textContent = "Ask the agent";
  ask.addEventListener("click", () => ctx.chat.send("What can you do in this studio?"), { signal: ctx.signal });
  root.append(title, lede, ask);
  el.append(root);
  return {
    unmount() {
      root.remove();
    },
  };
}
