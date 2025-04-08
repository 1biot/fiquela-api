<?php

namespace Api\Endpoints;

use Api;
use Fig\Http\Message\StatusCodeInterface as StatusCodes;
use FQL\Exception\FileNotFoundException;
use FQL\Exception\InvalidFormatException;
use Nette\Utils\Paginator;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use Tracy\Debugger;

class Query extends Controller
{
    public const int DefaultLimit = 1000;

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        Debugger::timer('query');
        try {
            $workspace = $this->getWorkspace($request);
            $data = $this->validateRequest($request, new Api\Schemas\Query);
            $file = $data['file'] ?? null;
            $query = $data['query'] ?? '';

            [$cachedQuery, $originQueryHash] = $workspace->runQuery($query, $file);
            $count = $cachedQuery->execute()->count();

            $paginator = new Paginator;
            $paginator->setItemCount($count);
            $paginator->setPage((int) ($data['page'] ?? 1));
            $paginator->setItemsPerPage(min(self::DefaultLimit, $count, (int) ($data['limit'] ?? self::DefaultLimit)));

            if ($paginator->getPageCount() > 1) {
                $cachedQuery->offset($paginator->getOffset())
                    ->limit($paginator->getItemsPerPage());
            }

            return $this->json(
                $response,
                [
                    'query' => (string) $query,
                    'hash' => $originQueryHash,
                    'data' => iterator_to_array($cachedQuery->execute()->getIterator()),
                    'elapsed' => round(Debugger::timer('query') * 1000, 2), // in milliseconds
                    'pagination' => [
                        'page' => $paginator->getPage(),
                        'pageCount' => $paginator->getPageCount(),
                        'itemCount' => $paginator->getItemCount(),
                        'itemsPerPage' => $paginator->getItemsPerPage(),
                        'offset' => $paginator->getOffset(),
                    ],
                ]
            );
        } catch (FileNotFoundException $e) {
            return $this->json($response, ['error' => $e->getMessage()], StatusCodes::STATUS_NOT_FOUND);
        } catch (InvalidFormatException $e) {
            return $this->json($response, ['error' => $e->getMessage()], StatusCodes::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], StatusCodes::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
