#!/usr/bin/env python3
"""HTTP baseline-vs-PR benchmark on one disposable Apache/MySQL deployment."""
import argparse
import concurrent.futures
import json
import math
import statistics
import time
import urllib.error
import urllib.request
parser=argparse.ArgumentParser()
parser.add_argument('--version',choices=('main','pr62'),required=True)
parser.add_argument('--dataset',type=int,choices=(10000,100000),required=True)
args=parser.parse_args()
root='http://localhost:8094'
paths={
  'public_original':'/wp-content/uploads/esp31-public.jpg',
  'protected_original':'/wp-content/uploads/esp31-protected.jpg',
  'unregistered':'/wp-content/uploads/esp42-perf-unregistered.jpg'
}
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs): return None
def send(path):
    opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect())
    req=urllib.request.Request(root+path,headers={'Cache-Control':'no-cache'})
    before=time.perf_counter_ns()
    try:
        res=opener.open(req,timeout=25)
    except urllib.error.HTTPError as err:
        res=err
    data=res.read()
    ms=(time.perf_counter_ns()-before)/1e6
    return (ms,res.code,len(data))
def summary(values):
    values=sorted(values)
    def p(x):
        n=(len(values)-1)*x
        lo=math.floor(n);hi=math.ceil(n)
        return round(values[lo]+(values[hi]-values[lo])*(n-lo),3)
    return {'n':len(values),'avg_ms':round(statistics.mean(values),3),
      'p50_ms':p(.5),'p95_ms':p(.95),'max_ms':round(max(values),3)}
results={}
for name,path in paths.items():
    expected={302,403} if name=='protected_original' else {200}
    for _ in range(6):
        _,status,_=send(path)
        if status not in expected:
            raise RuntimeError(f'HTTP warmup {name}: unexpected {status}')
    tests={}
    for concurrency,count in [(1,28),(8,48)]:
        if concurrency==1:
            data=[send(path) for _ in range(count)]
        else:
            with concurrent.futures.ThreadPoolExecutor(max_workers=concurrency) as pool:
                data=list(pool.map(send,[path]*count))
        failures=[x for x in data if x[1] not in expected]
        if failures:
            raise RuntimeError(f'HTTP {name} failed: {failures[:5]}')
        tests['serial' if concurrency==1 else 'parallel8']={
          **summary([x[0] for x in data]),
          'http_statuses':{str(code):sum(x[1]==code for x in data)
                           for code in sorted({x[1] for x in data})},
          'total_body_bytes':sum(x[2] for x in data)
        }
    results[name]=tests
print(json.dumps({'version':args.version,'fixture_size':args.dataset,'http':results},indent=2))
