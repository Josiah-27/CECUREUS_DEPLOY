import { createMockServer } from '@/test/mock-server';

const BASE = process.env.EXPO_PUBLIC_API_BASE_URL!;

describe('HTTP methods', () => {
  it('the API accepts only GET, POST, PUT and DELETE', async () => {
    const server = createMockServer();
    for (const method of ['PATCH', 'OPTIONS']) {
      const res = await server.fetch(`${BASE}/me`, { method });
      expect(res.status).toBe(405);
      expect(await res.json()).toMatchObject({ status: false, code: 'METHOD_NOT_ALLOWED' });
    }
    expect((await server.fetch(`${BASE}/issues`, { method: 'GET' })).status).toBe(200);
  });
});
