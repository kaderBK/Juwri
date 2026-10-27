// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

export const metadata = {
  title: 'Storefront',
  description: 'Headless storefront powered by the juwri Storefront API',
};

export default function RootLayout({ children }) {
  // dir is set from the restaurant's locale in a real build; RTL-ready by default.
  return (
    <html lang="en">
      <body style={{ margin: 0, fontFamily: 'system-ui, sans-serif', background: '#0f1115', color: '#e7e9ee' }}>
        {children}
      </body>
    </html>
  );
}
