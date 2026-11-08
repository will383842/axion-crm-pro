"""IMAP minimal (boîte vide) pour tester la lecture des retours."""
import asyncio


async def client(r, w):
    w.write(b"* OK IMAP banc pret\r\n")
    while not r.at_eof():
        l = (await r.readline()).decode(errors="replace").strip()
        if not l: break
        tag, _, cmd = l.partition(" ")
        c = cmd.split(" ")[0].upper()
        if c == "CAPABILITY": w.write(b"* CAPABILITY IMAP4rev1\r\n")
        elif c == "SELECT" or c == "EXAMINE": w.write(b"* 0 EXISTS\r\n* 0 RECENT\r\n")
        elif c == "LIST": w.write(b'* LIST () "/" INBOX\r\n')
        elif c == "SEARCH": w.write(b"* SEARCH\r\n")
        if c == "LOGOUT":
            w.write(b"* BYE\r\n" + tag.encode() + b" OK fin\r\n"); break
        w.write(tag.encode() + b" OK " + c.encode() + b" fait\r\n")
        await w.drain()
    w.close()


async def main():
    srv = await asyncio.start_server(client, "0.0.0.0", 143)
    async with srv:
        await srv.serve_forever()


if __name__ == "__main__":
    asyncio.run(main())
