/// <reference types="vite/client" />

// Vite's client types declare the asset modules our source imports for their
// side effects (`import "./styles.css"`). TypeScript 7 reports a side-effect
// import of an undeclared module as an error (TS2882) where 5.x stayed silent,
// so this reference is now load-bearing, not decorative.
